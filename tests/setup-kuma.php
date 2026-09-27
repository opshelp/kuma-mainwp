<?php
declare(strict_types=1);
require __DIR__ . '/environment.php';

// First-user setup for the empty, isolated CI service only. Never delete existing users/data.
$socket = new KumaMainWP\Socket(['url'=>'http://127.0.0.1:8878','allow_http'=>true]);
try {
    $socket->call('setup', ['kmw_test','Kuma-local-provisioning-2026!']);
    echo "Disposable Kuma user initialized.\n";
} finally { $socket->close(); }
