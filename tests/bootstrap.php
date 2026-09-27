<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Integration tests must use the installed plugin's autoloader, with no source fallback.
if (!defined('KMW_FILE')) { spl_autoload_register(function (string $class): void {
    $prefix = 'KumaMainWP\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/includes/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}); }

$tests = [];
function test(string $name, callable $body): void { global $tests; $tests[$name] = $body; }
function same($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function truth(bool $value, string $message = 'Assertion failed'): void {
    if (!$value) { throw new RuntimeException($message); }
}
function throws(callable $body): void {
    try { $body(); } catch (InvalidArgumentException | RuntimeException $e) { return; }
    throw new LogicException('Expected an exception');
}
function run_tests(): void {
    global $tests;
    $failed = 0;
    foreach ($tests as $name => $body) {
        try { $body(); echo "PASS $name\n"; }
        catch (Throwable $e) { ++$failed; echo "FAIL $name: {$e->getMessage()}\n"; }
    }
    echo count($tests) . " tests, $failed failed\n";
    exit($failed ? 1 : 0);
}
