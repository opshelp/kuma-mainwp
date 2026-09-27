<?php
declare(strict_types=1);
namespace KumaMainWP;

/** Database ownership must not depend on WordPress's request-local option cache. */
final class Lock
{
    public static function acquire(string $name, array $value): bool
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name));
        $old = maybe_unserialize($raw);
        if (is_array($old) && (int) ($old['expires'] ?? 0) < time()) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $raw));
        }
        // add_option() can upsert over a competing worker after its existence check.
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, maybe_serialize($value)));
        wp_cache_delete($name, 'options'); wp_cache_delete('notoptions', 'options');
        return $inserted === 1;
    }

    public static function release(string $name, array $value): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, maybe_serialize($value)));
        wp_cache_delete($name, 'options'); wp_cache_delete('notoptions', 'options');
    }

    public static function provision(): array
    {
        $value = ['token'=>bin2hex(random_bytes(16)), 'expires'=>time()+120];
        if (!self::acquire('kmw_provision_lock', $value)) { throw new \RuntimeException('Another Kuma settings or monitor-creation action is running. Wait for it to finish, then try again.'); }
        foreach (['kmw_settings','kmw_mappings','kmw_management','kmw_inventory','kmw_pending','kmw_state'] as $option) { wp_cache_delete($option, 'options'); }
        return $value;
    }
}
