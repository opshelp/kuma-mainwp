<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Exercise a source installation without loading WordPress or touching its database.
test('development entry points reject HTTP before doing any work', function (): void {
    $root = dirname(__DIR__);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    truth($listener !== false, 'Could not reserve a local port');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $log = tempnam(sys_get_temp_dir(), 'kmw-http-');
    $env = getenv();
    unset($env['KMW_TESTS_ALLOW_DESTRUCTIVE']);
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', $root], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes, $root, $env);
    truth(is_resource($process), 'Could not start HTTP guard check');
    fclose($pipes[0]);
    try {
        $ready = false;
        for ($i = 0; $i < 100; ++$i) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($probe) { fclose($probe); $ready = true; break; }
            usleep(20000);
        }
        truth($ready, 'HTTP guard check did not start');
        foreach (['tests/bootstrap.php','tests/environment.php','tests/run.php','tests/security.php','tests/package.php','tests/integration.php','tests/mainwp.php','tests/provisioning.php','tests/change-config.php','tests/setup-kuma.php','tests/metrics-server.php','scripts/package.php'] as $entry) {
            $stream = fopen('http://' . $address . '/' . $entry, 'rb', false, stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>5]]));
            $body = stream_get_contents($stream); $headers = stream_get_meta_data($stream)['wrapper_data']; fclose($stream);
            truth(str_contains($headers[0], '403'), $entry . ' must return HTTP 403');
            same('', $body);
        }
    } finally { proc_terminate($process); proc_close($process); unlink($log); }
});

test('destructive suites refuse to load WordPress without explicit opt-in', function (): void {
    $env = getenv();
    unset($env['KMW_TESTS_ALLOW_DESTRUCTIVE']);
    foreach (['integration.php','mainwp.php','provisioning.php','change-config.php','setup-kuma.php'] as $file) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/' . $file], [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $env);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        same(1, proc_close($process));
        same('', $out);
        truth(str_contains($err, 'KMW_TESTS_ALLOW_DESTRUCTIVE=1'), $file . ' must refuse before loading WordPress');
    }
});

test('environment opt-in alone cannot authorize an unmarked WordPress installation', function (): void {
    $site = sys_get_temp_dir() . '/kmw-unmarked-' . bin2hex(random_bytes(8));
    mkdir($site,0700);file_put_contents($site.'/wp-load.php', '<?php // Simulate an unmarked installation.');
    $env=getenv();$env['KMW_TESTS_ALLOW_DESTRUCTIVE']='1';$env['KMW_TEST_WORDPRESS']=$site;
    try {
        $process=proc_open([PHP_BINARY,__DIR__.'/change-config.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$env);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        same(1,proc_close($process));same('',$out);truth(str_contains($err,'KMW_TEST_INSTANCE'));
    } finally { unlink($site.'/wp-load.php');rmdir($site); }
});

run_tests();
