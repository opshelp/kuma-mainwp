<?php
declare(strict_types=1);
namespace KumaMainWP;

final class Poller
{
    public static function state(): array
    {
        $state = get_option('kmw_state', []);
        if (!is_array($state) || ($state['revision'] ?? '') !== Settings::get()['revision']) { $state = []; }
        return array_merge(['monitors'=>[],'success_at'=>0,'attempt_at'=>0,'error'=>''], is_array($state) ? $state : []);
    }

    public static function stale(array $state): bool
    {
        return !empty($state['error']) || empty($state['success_at']) || time() - (int) $state['success_at'] > 180;
    }

    public static function refresh(): array
    {
        $settings = Settings::get();
        if ($settings['url'] === '') { return self::state(); }
        $lock = ['token'=>bin2hex(random_bytes(16)), 'expires'=>time()+60];
        if (!Lock::acquire('kmw_lock', $lock)) { return self::state(); }
        $state = self::state();
        try {
            $state['attempt_at'] = time();
            try {
                $monitors = Client::fetch($settings, Settings::key());
                $state = ['monitors'=>$monitors,'success_at'=>time(),'attempt_at'=>$state['attempt_at'],'error'=>''];
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                $state['error'] = $e->getMessage();
            }
            // The tag also makes a result written across the revision-check/write race unreadable by the new connection.
            $state['revision'] = $settings['revision'];
            if (Settings::freshRevision() === $settings['revision']) { update_option('kmw_state', $state, false); }
            return self::state();
        } finally {
            Lock::release('kmw_lock', $lock);
        }
    }

    public static function schedules(array $schedules): array
    {
        $schedules['kmw_minute'] = ['interval'=>60,'display'=>'Every minute (Kuma)'];
        return $schedules;
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled('kmw_refresh')) { wp_schedule_event(time()+10, 'kmw_minute', 'kmw_refresh'); }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('kmw_refresh');
        delete_option('kmw_lock');
    }
}
