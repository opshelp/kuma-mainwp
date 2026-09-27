<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

if (getenv('KMW_TESTS_ALLOW_DESTRUCTIVE') !== '1') {
    fwrite(STDERR, "Refusing destructive tests. Set KMW_TESTS_ALLOW_DESTRUCTIVE=1 only for a disposable test installation.\n");
    exit(1);
}
define('WP_ADMIN', true);
$wordpress = getenv('KMW_TEST_WORDPRESS') ?: dirname(__DIR__) . '/.dev/wordpress';
require $wordpress . '/wp-load.php';
if (!defined('KMW_TEST_INSTANCE') || KMW_TEST_INSTANCE !== true) {
    fwrite(STDERR, "Refusing destructive tests. The disposable wp-config.php must define KMW_TEST_INSTANCE as true.\n");
    exit(1);
}
if (!defined('KMW_FILE')) {
    fwrite(STDERR, "Activate the packaged Kuma plugin in the disposable WordPress installation first.\n");
    exit(1);
}
require_once __DIR__ . '/bootstrap.php';
