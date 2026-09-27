<?php
declare(strict_types=1);
namespace KumaMainWP;

final class Management
{
    public static function get(): array
    {
        $value = get_option('kmw_management', []);
        return is_array($value) && ($value['url'] ?? '') === Settings::get()['url'] ? $value : [];
    }

    public static function freshId(): string
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'kmw_management'));
        $value = maybe_unserialize($raw);
        return is_array($value) ? (string) ($value['id'] ?? '') : '';
    }

    public static function connect(string $username, string $password, string $code, string $revision): void
    {
        Admin::requireAdmin();
        // Capture and validate the same endpoint before sending this form's credentials.
        $settings = Settings::get();
        if ($revision === '' || $settings['revision'] !== $revision || Settings::freshRevision() !== $revision) { throw new \InvalidArgumentException('The connection changed. Reload this page before signing in.'); }
        if ($username === '' || $password === '' || strlen($username) > 250 || strlen($password) > 4096 || ($code !== '' && !preg_match('/^[0-9]{6}$/D', $code))) { throw new \InvalidArgumentException('Enter your Kuma username, password and optional six-digit code.'); }
        $socket = new Socket($settings);
        try {
            $login = $socket->call('login', [['username'=>$username,'password'=>$password,'token'=>$code]]);
            if (!is_string($login['token'] ?? null) || $login['token'] === '' || strlen($login['token']) > 8192) { throw new \RuntimeException('Kuma did not return a management session token.'); }
            $snapshot = $socket->inventory();
            if (Settings::freshRevision() !== $revision) { throw new \RuntimeException('The connection changed during sign-in. Reload and try again.'); }
            $session = ['url'=>$settings['url'],'id'=>bin2hex(random_bytes(16)),'token'=>self::encrypt($login['token'])];
            update_option('kmw_management', $session, false);
            self::storeSnapshot($snapshot, $session);
        } finally { $socket->close(); }
    }

    public static function open(): Socket
    {
        $session = self::get();
        if (empty($session['token'])) { throw new \RuntimeException('Sign in to Kuma management before adding monitors.'); }
        $socket = new Socket(Settings::get());
        try { $socket->call('loginByToken', [self::decrypt($session['token'])]); }
        catch (\RuntimeException $e) { $socket->close(); throw $e; }
        return $socket;
    }

    public static function snapshot(): array
    {
        $value = get_option('kmw_inventory', []); $session = self::get();
        return !empty($session['id']) && is_array($value) && ($value['session'] ?? '') === $session['id'] ? $value : ['monitors'=>[],'notifications'=>[],'checked_at'=>0];
    }

    public static function storeSnapshot(array $snapshot, array $session): void
    {
        update_option('kmw_inventory', array_merge($snapshot, ['session'=>$session['id'],'checked_at'=>time()]), false);
    }

    public static function refresh(): void
    {
        Admin::requireAdmin(); $session = self::get(); $socket = self::open();
        try { self::storeSnapshot($socket->inventory(), $session); }
        finally { $socket->close(); }
    }

    public static function disconnect(): void
    {
        Admin::requireAdmin();
        delete_option('kmw_management'); delete_option('kmw_inventory');
    }

    private static function encrypt(string $plain): string
    {
        if (!function_exists('openssl_encrypt')) { throw new \RuntimeException('Enable PHP OpenSSL to save a management connection.'); }
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) { throw new \RuntimeException('Could not encrypt the management session.'); }
        return base64_encode($iv . $tag . $encrypted);
    }

    private static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29 || !function_exists('openssl_decrypt')) { throw new \RuntimeException('Sign in to Kuma management again; the saved session cannot be decrypted.'); }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) { throw new \RuntimeException('Sign in to Kuma management again; the saved session cannot be decrypted.'); }
        return $plain;
    }

    private static function key(): string { return hash('sha256', wp_salt('auth') . '|kuma-mainwp|management-v1', true); }
}
