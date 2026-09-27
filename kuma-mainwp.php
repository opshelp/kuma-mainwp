<?php
/**
 * Plugin Name: Kuma Monitor for MainWP
 * Description: Connect private Uptime Kuma metrics to MainWP site status and rolling uptime report tokens.
 * Version: 0.2.1
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Kuma Monitor Contributors
 * License: GPL-2.0-or-later
 * Text Domain: kuma-mainwp
 */
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }
define('KMW_VERSION', '0.2.1');
define('KMW_FILE', __FILE__);
spl_autoload_register(static function (string $class): void {
    $prefix = 'KumaMainWP\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z]+$/D', $name) && is_file(__DIR__ . '/includes/' . $name . '.php')) { require_once __DIR__ . '/includes/' . $name . '.php'; }
    }
});
KumaMainWP\Plugin::boot();
register_activation_hook(__FILE__, [KumaMainWP\Poller::class, 'schedule']);
register_deactivation_hook(__FILE__, [KumaMainWP\Poller::class, 'deactivate']);
