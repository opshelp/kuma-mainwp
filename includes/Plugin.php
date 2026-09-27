<?php
declare(strict_types=1);
namespace KumaMainWP;

final class Plugin
{
    public static function boot(): void
    {
        add_filter('cron_schedules', [Poller::class,'schedules']);
        add_action('kmw_refresh', [Poller::class,'refresh']);
        add_action('init', [Poller::class,'schedule']);
        add_filter('mainwp_getextensions', [self::class,'extension']);
        add_filter('mainwp_sitestable_getcolumns', [self::class,'columns']);
        add_filter('mainwp_sitestable_item', [self::class,'column'], 10, 2);
        add_filter('mainwp_pro_reports_custom_tokens', [self::class,'reportTokens'], 10, 3);
        add_action('admin_menu', [Admin::class,'menu']);
        add_action('admin_post_kmw_action', [Admin::class,'handle']);
        add_action('admin_enqueue_scripts', [Admin::class,'assets']);
        add_filter('plugin_action_links_' . plugin_basename(KMW_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=kuma-mainwp')) . '">Settings</a>');
            return $links;
        });
    }

    public static function ready(): bool
    {
        return (bool) apply_filters('mainwp_activated_check', false);
    }

    public static function extension(array $extensions): array
    {
        $extensions[] = ['plugin'=>KMW_FILE,'api'=>'kuma-mainwp','mainwp'=>false,'callback'=>[Admin::class,'extensionPage']];
        return $extensions;
    }

    public static function sites(): array
    {
        if (!self::ready() || !current_user_can('manage_options')) { return []; }
        $enabled = apply_filters('mainwp_extension_enabled_check', KMW_FILE);
        if (!is_array($enabled) || empty($enabled['key'])) { return []; }
        $sites = apply_filters('mainwp_getsites', KMW_FILE, $enabled['key'], null);
        if (!is_array($sites)) { return []; }
        return array_values(array_filter($sites, static fn($site): bool => is_array($site) && isset($site['id']) && (!function_exists('mainwp_current_user_can') || mainwp_current_user_can('site', $site['id']))));
    }

    public static function mappings(): array
    {
        $value = get_option('kmw_mappings', []);
        return is_array($value) && ($value['revision'] ?? '') === Settings::get()['revision'] && is_array($value['sites'] ?? null) ? $value['sites'] : [];
    }

    public static function columns(array $columns): array
    {
        if (self::canViewColumns()) { $columns['kmw_status'] = 'Kuma'; }
        return $columns;
    }

    public static function column(array $item, string $column = ''): array
    {
        if ($column !== 'kmw_status' || !self::canViewColumns() || empty($item['id'])) { return $item; }
        if (function_exists('mainwp_current_user_can') && !mainwp_current_user_can('site', $item['id'])) { $item['kmw_status'] = ''; return $item; }
        $item['kmw_status'] = Presentation::column($item, Poller::state(), self::mappings());
        return $item;
    }

    public static function canViewColumns(): bool
    {
        return current_user_can('manage_options') || (is_user_logged_in() && function_exists('MainWP\\Dashboard\\mainwp_current_user_have_right') && mainwp_current_user_can('dashboard', 'access_global_dashboard'));
    }

    public static function reportTokens($tokens, $report, $website)
    {
        if (!is_array($tokens)) { return $tokens; }
        $site = is_object($website) ? get_object_vars($website) : $website;
        if (!is_array($site) || empty($site['id'])) { return $tokens; }
        // Pro Reports supplies an authorized site, including during scheduled jobs with no logged-in user.
        $values = Presentation::tokens($site, Poller::state(), self::mappings());
        foreach ($values as $key=>$value) { if (array_key_exists($key, $tokens)) { $tokens[$key] = $value; } }
        return $tokens;
    }
}
