<?php
// Controlled HTTP integration fixture. Never package or deploy this server.
if (PHP_SAPI !== 'cli-server' || getenv('KMW_TESTS_ALLOW_DESTRUCTIVE') !== '1') { http_response_code(403); exit; }
if ($_SERVER['REQUEST_URI'] === '/redirect/metrics') { header('Location: http://127.0.0.1:8772/metrics', true, 302); exit; }
if ($_SERVER['REQUEST_URI'] !== '/metrics') { http_response_code(404); exit; }
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Basic ' . base64_encode(':fixture-secret')) { http_response_code(401); echo 'Authentication required'; exit; }
header('Content-Type: text/plain; version=0.0.4');
readfile(__DIR__ . '/fixtures/demo.prom');
