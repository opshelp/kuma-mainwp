<?php
declare(strict_types=1);
namespace KumaMainWP;

final class Admin
{
    public static function menu(): void
    {
        add_options_page('Kuma Monitor', 'Kuma Monitor', 'manage_options', 'kuma-mainwp', [self::class,'render']);
    }

    public static function assets(): void
    {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (Plugin::canViewColumns() && ($page === 'kuma-mainwp' || $page === 'managesites' || str_contains($page, 'kuma-mainwp'))) {
            wp_enqueue_style('kuma-mainwp', plugins_url('assets/admin.css', KMW_FILE), [], KMW_VERSION);
        }
    }

    public static function requireAdmin(): void
    {
        if (!current_user_can('manage_options')) { wp_die('You do not have permission to manage Kuma.', '', ['response'=>403]); }
    }

    public static function extensionPage(): void
    {
        self::requireAdmin();
        do_action('mainwp_pageheader_extensions', KMW_FILE);
        self::render();
        do_action('mainwp_pagefooter_extensions', KMW_FILE);
    }

    public static function render(): void
    {
        self::requireAdmin();
        $settings = Settings::get(); $state = Poller::state(); $sites = Plugin::sites(); $mappings = Plugin::mappings();
        $mappingMonitors = array_replace(Management::snapshot()['monitors'], $state['monitors']);
        $flash = get_transient('kmw_notice_' . get_current_user_id());
        delete_transient('kmw_notice_' . get_current_user_id());
        require dirname(__DIR__) . '/views/admin.php';
    }

    public static function validateMappings(array $input, array $sites, array $monitors, array $existing): array
    {
        $allowed = array_fill_keys(array_map(static fn(array $site): string => (string) $site['id'], $sites), true);
        $result = $existing;
        foreach ($input as $siteId=>$monitorId) {
            if (!isset($allowed[$siteId]) || !is_scalar($monitorId)) { throw new \InvalidArgumentException('Invalid site mapping. Reload the page and try again.'); }
            $monitorId = (string) $monitorId;
            if ($monitorId !== '' && $monitorId !== 'none' && !isset($monitors[$monitorId]) && ($existing[$siteId] ?? '') !== $monitorId) { throw new \InvalidArgumentException('The selected monitor is unavailable. Refresh and try again.'); }
            if ($monitorId === '') { unset($result[$siteId]); } else { $result[$siteId] = $monitorId; }
        }
        return $result;
    }

    public static function handle(): void
    {
        self::requireAdmin();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Use the settings form.', '', ['response'=>405]); }
        check_admin_referer('kmw_action');
        $input = wp_unslash($_POST);
        $op = is_string($input['kmw_op'] ?? null) ? $input['kmw_op'] : '';
        try {
            if ($op === 'save') {
                foreach (['url','api_key'] as $field) { if (!is_string($input[$field] ?? null)) { throw new \InvalidArgumentException('Invalid settings form.'); } }
                Settings::save($input);
                $state = Poller::refresh();
                $message = empty($state['error']) ? 'Settings saved. Connection successful.' : 'Settings saved. ' . $state['error'];
                $error = !empty($state['error']);
            } elseif ($op === 'refresh') {
                $state = Poller::refresh();
                $error = !empty($state['error']) || empty($state['success_at']);
                $message = $error ? ($state['error'] ?: 'Save a connection first, or wait for the current refresh to finish.') : 'Metrics refreshed.';
            } elseif ($op === 'mappings') {
                if (!is_array($input['mappings'] ?? null)) { throw new \InvalidArgumentException('No mappings submitted.'); }
                self::saveMappings($input['mappings'], is_string($input['connection_revision'] ?? null) ? $input['connection_revision'] : '', Plugin::sites());
                $message = 'Site mappings saved.'; $error = false;
            } elseif ($op === 'management_connect') {
                foreach (['username','password','two_factor','connection_revision'] as $field) { if (!is_string($input[$field] ?? null)) { throw new \InvalidArgumentException('Invalid management connection form.'); } }
                Management::connect($input['username'], $input['password'], $input['two_factor'], $input['connection_revision']);
                $message = 'Kuma management connected. Review the missing sites below.'; $error = false;
            } elseif ($op === 'management_refresh' || $op === 'management_disconnect') {
                if (empty($input['management_revision']) || $input['management_revision'] !== Management::freshId() || ($input['connection_revision'] ?? '') !== Settings::freshRevision()) { throw new \InvalidArgumentException('The connection changed. Reload this page.'); }
                if ($op === 'management_refresh') { Management::refresh(); $message = 'Full Kuma monitor list refreshed, including paused monitors.'; }
                else { Management::disconnect(); $message = 'Management connection removed. Metrics access is unchanged.'; }
                $error = false;
            } elseif ($op === 'create_monitors') {
                if (!is_array($input['site_ids'] ?? null) || !is_array($input['notifications'] ?? []) || !is_string($input['connection_revision'] ?? null) || !is_string($input['management_revision'] ?? null)) { throw new \InvalidArgumentException('Select the sites you want to add.'); }
                $interval = filter_var($input['interval'] ?? null, FILTER_VALIDATE_INT);
                if ($interval === false) { throw new \InvalidArgumentException('Enter a whole number of seconds for the check interval.'); }
                $result = Provisioner::create($input['site_ids'], $interval, $input['notifications'] ?? [], $input['connection_revision'], $input['management_revision'], Plugin::sites());
                $message = sprintf('%d monitors created. %d existing monitors linked. %d sites skipped.', $result['created'], $result['linked'], $result['skipped']);
                $error = $result['error'] !== '';
                if ($error) { $message .= ' ' . $result['error']; }
                elseif ($result['created'] || $result['linked']) { Poller::refresh(); }
            } elseif ($op === 'management_retry') {
                if (!is_array($input['site_ids'] ?? null) || !is_string($input['connection_revision'] ?? null) || !is_string($input['management_revision'] ?? null)) { throw new \InvalidArgumentException('Select the pending sites you have checked in Kuma.'); }
                Provisioner::allowRetry($input['site_ids'], $input['connection_revision'], $input['management_revision'], Plugin::sites());
                $message = 'Pending attempts cleared for the selected sites. You can now select them for creation.'; $error = false;
            } else { throw new \InvalidArgumentException('Unknown action.'); }
        } catch (\RuntimeException | \InvalidArgumentException $e) { $message = $e->getMessage(); $error = true; }
        set_transient('kmw_notice_' . get_current_user_id(), ['message'=>$message,'error'=>$error], 60);
        wp_safe_redirect(admin_url(($input['kmw_page'] ?? '') === 'Extensions-Kuma-Mainwp' ? 'admin.php?page=Extensions-Kuma-Mainwp' : 'options-general.php?page=kuma-mainwp'));
        exit;
    }

    public static function formStart(string $op): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kmw_action"><input type="hidden" name="kmw_op" value="' . esc_attr($op) . '">';
        wp_nonce_field('kmw_action');
        $page = ($_GET['page'] ?? '') === 'Extensions-Kuma-Mainwp' ? 'Extensions-Kuma-Mainwp' : 'kuma-mainwp';
        echo '<input type="hidden" name="kmw_page" value="' . esc_attr($page) . '">';
        echo '<input type="hidden" name="connection_revision" value="' . esc_attr(Settings::get()['revision']) . '">';
        if (str_starts_with($op, 'management_') || $op === 'create_monitors') { echo '<input type="hidden" name="management_revision" value="' . esc_attr(Management::get()['id'] ?? '') . '">'; }
    }

    public static function saveMappings(array $input, string $revision, array $sites): void
    {
        $lock = Lock::provision();
        try { self::persistMappings($input, $revision, $sites); }
        finally { Lock::release('kmw_provision_lock', $lock); }
    }

    private static function persistMappings(array $input, string $revision, array $sites): void
    {
        if ($revision === '' || Settings::freshRevision() !== $revision) { throw new \InvalidArgumentException('The connection changed while this page was open. Reload before saving mappings.'); }
        $monitors = array_replace(Management::snapshot()['monitors'], Poller::state()['monitors']);
        $mappings = self::validateMappings($input, $sites, $monitors, Plugin::mappings());
        update_option('kmw_mappings', ['revision'=>$revision,'sites'=>$mappings], false);
    }
}
