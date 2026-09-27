<?php
declare(strict_types=1);
namespace KumaMainWP;

final class Settings
{
    public static function get(): array
    {
        $stored = get_option('kmw_settings', []);
        return array_merge(['url'=>'','key'=>'','allow_http'=>false,'revision'=>''], is_array($stored) ? $stored : []);
    }

    public static function save(array $input): void
    {
        $lock = Lock::provision();
        try { self::persist($input); }
        finally { Lock::release('kmw_provision_lock', $lock); }
    }

    private static function persist(array $input): void
    {
        $old = self::get();
        $allowHttp = !empty($input['allow_http']);
        $url = self::normalizeUrl((string) ($input['url'] ?? ''), $allowHttp);
        $changed = $url !== $old['url'];
        $key = $changed || !empty($input['clear_key']) ? '' : $old['key'];
        $plain = trim((string) ($input['api_key'] ?? ''));
        if (strlen($plain) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $plain)) { throw new \InvalidArgumentException('The API key has an invalid format.'); }
        if ($plain !== '' && !defined('KMW_API_KEY')) { $key = self::encrypt($plain); }
        $revision = bin2hex(random_bytes(16));
        $mappings = get_option('kmw_mappings', []);
        update_option('kmw_settings', ['url'=>$url,'key'=>$key,'allow_http'=>$allowHttp,'revision'=>$revision], false);
        // Credentials can grant access to a different set of monitors on the same endpoint.
        delete_option('kmw_state');
        if ($changed) {
            delete_option('kmw_mappings'); delete_option('kmw_management'); delete_option('kmw_inventory'); delete_option('kmw_pending');
        }
        elseif (is_array($mappings) && ($mappings['revision'] ?? '') === $old['revision']) {
            $mappings['revision'] = $revision;
            update_option('kmw_mappings', $mappings, false);
        }
    }

    public static function normalizeUrl(string $url, bool $allowHttp): string
    {
        $url = rtrim(trim($url), '/');
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { throw new \InvalidArgumentException('Enter a valid Kuma base URL.'); }
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['scheme'] ?? '', ['https','http'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Use an HTTP(S) base URL without credentials, query strings or fragments.');
        }
        if ($p['scheme'] === 'http' && !$allowHttp) { throw new \InvalidArgumentException('Use HTTPS, or explicitly enable HTTP for a trusted private network.'); }
        if (str_ends_with($url, '/metrics')) { $url = substr($url, 0, -8); }
        return $url;
    }

    public static function key(): string
    {
        if (defined('KMW_API_KEY')) { return (string) KMW_API_KEY; }
        $encoded = self::get()['key'];
        if ($encoded === '') { return ''; }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29 || !function_exists('openssl_decrypt')) { throw new \RuntimeException('The saved API key cannot be decrypted. Enter it again.'); }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) { throw new \RuntimeException('The saved API key cannot be decrypted. Enter it again.'); }
        return $plain;
    }

    /** Bypass WordPress's request-local option cache when committing a network result. */
    public static function freshRevision(): string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'kmw_settings'));
        $settings = maybe_unserialize($value);
        return is_array($settings) ? (string) ($settings['revision'] ?? '') : '';
    }

    private static function encrypt(string $plain): string
    {
        if (!function_exists('openssl_encrypt')) { throw new \RuntimeException('Enable the PHP OpenSSL extension to store an API key, or define KMW_API_KEY in wp-config.php.'); }
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) { throw new \RuntimeException('Could not encrypt the API key.'); }
        return base64_encode($iv . $tag . $encrypted);
    }

    private static function encryptionKey(): string
    {
        return hash('sha256', wp_salt('auth') . '|kuma-mainwp|v1', true);
    }
}
