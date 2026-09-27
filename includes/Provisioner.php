<?php
declare(strict_types=1);
namespace KumaMainWP;

final class Provisioner
{
    public static function candidates(array $sites, array $monitors, array $mappings, array $pending = []): array
    {
        $result = [];
        foreach ($sites as $site) {
            $id = (string) $site['id']; $url = (string) $site['url'];
            $match = Matcher::find($site, $monitors, $mappings);
            $reason = $match['reason'];
            if ($reason === 'unmatched') {
                $host = self::host($url);
                if ($host === '' || !filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { $reason = 'invalid'; }
                else {
                    foreach ($monitors as $monitor) {
                        if (self::host((string) ($monitor['url'] ?? '')) === $host) { $reason = 'related'; break; }
                    }
                    foreach ($pending as $pendingId=>$pendingUrl) {
                        if ((string) $pendingId === $id || self::host((string) $pendingUrl) === $host) { $reason = 'pending'; break; }
                    }
                }
            }
            $result[$id] = ['site'=>$site,'monitor'=>$match['monitor'],'reason'=>$reason,'eligible'=>$reason === 'unmatched'];
        }
        return $result;
    }

    public static function pending(): array
    {
        $stored = get_option('kmw_pending', []);
        return is_array($stored) && ($stored['url'] ?? '') === Settings::get()['url'] && is_array($stored['sites'] ?? null) ? $stored['sites'] : [];
    }

    public static function create(array $ids, int $interval, array $notificationIds, string $revision, string $managementRevision, array $sites): array
    {
        Admin::requireAdmin(); self::assertCurrent($revision, $managementRevision);
        if (!$ids || count($ids) > 10 || $interval < 20 || $interval > 86400) { throw new \InvalidArgumentException('Select one to ten sites and an interval between 20 and 86400 seconds.'); }
        $byId = array_column($sites, null, 'id'); $selected = [];
        foreach ($ids as $id) {
            if (!is_scalar($id) || !isset($byId[(string) $id])) { throw new \InvalidArgumentException('An unknown or inaccessible MainWP site was selected. Reload the list.'); }
            $selected[(string) $id] = $byId[(string) $id];
        }
        foreach ($notificationIds as $id) { if (!is_scalar($id)) { throw new \InvalidArgumentException('Invalid notification selection.'); } }
        $lock = Lock::provision();
        $socket = null; $result = ['created'=>0,'linked'=>0,'skipped'=>0,'error'=>''];
        $settings = Settings::get(); $session = Management::get(); $started = microtime(true);
        try {
            $socket = Management::open();
            foreach ($selected as $id=>$site) {
                if (microtime(true) - $started > 25) { $result['error'] = 'The batch time limit was reached. Submit the remaining sites again.'; break; }
                self::assertCurrent($revision, $managementRevision);
                $inventory = $socket->inventory();
                self::assertCurrent($revision, $managementRevision);
                Management::storeSnapshot($inventory, $session);
                $notifications = [];
                foreach ($notificationIds as $notificationId) {
                    $notificationId = (string) $notificationId;
                    if (empty($inventory['notifications'][$notificationId]['active'])) { throw new \InvalidArgumentException('A selected notification is no longer available. Refresh the list.'); }
                    $notifications[$notificationId] = true;
                }
                wp_cache_delete('kmw_mappings', 'options'); wp_cache_delete('kmw_pending', 'options');
                $mappings = Plugin::mappings(); $pending = self::pending();
                $candidate = self::candidates([$site], $inventory['monitors'], $mappings, $pending)[$id];
                if ($candidate['monitor']) {
                    $mappings[$id] = $candidate['monitor']['id']; ++$result['linked'];
                } elseif (!$candidate['eligible']) { ++$result['skipped']; continue; }
                else {
                    self::assertCurrent($revision, $managementRevision);
                    // Retain this journal if the remote acknowledgement is lost: never blindly retry a write.
                    $pending[$id] = $site['url'];
                    self::savePending($settings['url'], $pending);
                    $created = $socket->call('add', [[
                        'name'=>$site['name'] ?: $site['url'], 'url'=>$site['url'], 'type'=>'http', 'method'=>'GET', 'active'=>true,
                        'interval'=>$interval, 'retryInterval'=>$interval, 'maxretries'=>2, 'resendInterval'=>0,
                        'timeout'=>min(48, $interval * 0.8), 'maxredirects'=>10, 'ignoreTls'=>false, 'upsideDown'=>false,
                        'accepted_statuscodes'=>['200-299'], 'notificationIDList'=>(object) $notifications, 'conditions'=>[],
                    ]]);
                    if (!preg_match('/^[1-9][0-9]{0,15}$/D', (string) ($created['monitorID'] ?? ''))) { throw new \RuntimeException('Kuma did not confirm a monitor ID. Refresh the list and check Kuma before trying again.'); }
                    ++$result['created'];
                    self::assertCurrent($revision, $managementRevision);
                    $mappings[$id] = (string) $created['monitorID'];
                }
                self::assertCurrent($revision, $managementRevision);
                update_option('kmw_mappings', ['revision'=>$revision,'sites'=>$mappings], false);
                unset($pending[$id]);
                update_option('kmw_pending', ['url'=>$settings['url'],'sites'=>$pending], false);
            }
            if ($result['error'] === '') {
                $inventory = $socket->inventory();
                self::assertCurrent($revision, $managementRevision);
                Management::storeSnapshot($inventory, $session);
            }
        } catch (\RuntimeException | \InvalidArgumentException $e) { $result['error'] = $e->getMessage(); }
        finally {
            if ($socket) { $socket->close(); }
            Lock::release('kmw_provision_lock', $lock);
        }
        return $result;
    }

    private static function assertCurrent(string $revision, string $managementRevision): void
    {
        if ($revision === '' || $managementRevision === '' || Settings::freshRevision() !== $revision || Management::freshId() !== $managementRevision) {
            throw new \InvalidArgumentException('The Kuma connection changed. Reload the page before adding monitors.');
        }
    }

    /** Explicit recovery after an administrator has checked an uncertain add in Kuma. */
    public static function allowRetry(array $ids, string $revision, string $managementRevision, array $sites): void
    {
        Admin::requireAdmin(); self::assertCurrent($revision, $managementRevision);
        $allowed = array_column($sites, null, 'id');
        if (!$ids || count($ids) > 10) { throw new \InvalidArgumentException('Select one to ten pending sites to review.'); }
        foreach ($ids as $id) { if (!is_scalar($id) || !isset($allowed[(string) $id])) { throw new \InvalidArgumentException('Invalid MainWP site selection.'); } }
        $lock = Lock::provision();
        $socket = null;
        try {
            $socket = Management::open(); $inventory = $socket->inventory();
            self::assertCurrent($revision, $managementRevision);
            $pending = self::pending();
            $candidates = self::candidates($sites, $inventory['monitors'], Plugin::mappings());
            foreach ($ids as $id) {
                if (!isset($pending[$id]) || !$candidates[$id]['eligible']) { throw new \InvalidArgumentException('A selected site now has a monitor or needs mapping review. Refresh the list and link it instead.'); }
                unset($pending[$id]);
            }
            self::assertCurrent($revision, $managementRevision);
            self::savePending(Settings::get()['url'], $pending);
        } finally {
            if ($socket) { $socket->close(); }
            Lock::release('kmw_provision_lock', $lock);
        }
    }

    private static function savePending(string $url, array $pending): void
    {
        global $wpdb;
        $value = ['url'=>$url,'sites'=>$pending];
        update_option('kmw_pending', $value, false);
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'kmw_pending'));
        if (maybe_unserialize($raw) !== $value) { throw new \RuntimeException('Could not save the creation journal. No new monitor request was sent. Check the WordPress database.'); }
    }

    private static function host(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['https','http'], true) || isset($parts['user']) || isset($parts['pass'])) { return ''; }
        return preg_replace('/^www\./', '', rtrim(strtolower((string) ($parts['host'] ?? '')), '.'));
    }
}
