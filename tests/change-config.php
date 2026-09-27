<?php
// Separate WordPress process used by the concurrent settings-save regression.
require __DIR__ . '/environment.php';
KumaMainWP\Settings::save(['url'=>'https://concurrent.example.test','api_key'=>'concurrent-key','allow_http'=>false]);
