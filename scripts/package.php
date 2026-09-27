<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = realpath(dirname(__DIR__));
if (!in_array($argv[1] ?? '', ['', '--source'], true) || count($argv) > 2) { fwrite(STDERR, "Usage: php scripts/package.php [--source]\n"); exit(1); }
$source = ($argv[1] ?? '') === '--source';
$header = file_get_contents($root . '/kuma-mainwp.php');
if (!preg_match('/Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/', $header, $match)) { throw new RuntimeException('Plugin version not found'); }
$version = $match[1];
$manifest = json_decode(file_get_contents(__DIR__ . '/release-files.json'), true, 512, JSON_THROW_ON_ERROR);
$files = array_merge($manifest['plugin'], $source ? $manifest['source'] : []);
if (count($files) !== count(array_unique($files))) { throw new RuntimeException('Duplicate release manifest entry'); }
sort($files);
foreach ($files as $file) {
    if (!is_string($file) || !preg_match('~^[A-Za-z0-9_.\-/]+$~D', $file) || str_starts_with($file, '/') || in_array('..', explode('/', $file), true)) { throw new RuntimeException('Invalid release path'); }
    $part = $root;
    foreach (explode('/', $file) as $segment) {
        $part .= '/' . $segment;
        if (is_link($part)) { throw new RuntimeException('Symlinked release path: ' . $file); }
    }
    if (!is_file($part)) { throw new RuntimeException('Missing release file: ' . $file); }
}
if (is_link($root . '/dist')) { throw new RuntimeException('Build directory must not be a symlink'); }
if (!is_dir($root . '/dist')) { mkdir($root . '/dist', 0755); }
$path = $root . '/dist/kuma-mainwp-' . $version . ($source ? '-source' : '') . '.zip';
if (is_link($path) || is_link($path . '.sha256')) { throw new RuntimeException('Build outputs must not be symlinks'); }
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Cannot create ZIP'); }
foreach ($files as $file) {
    $name = 'kuma-mainwp/' . $file;
    if (!$zip->addFile($root . '/' . $file, $name)) { throw new RuntimeException('Cannot add release file: ' . $file); }
    $zip->setMtimeName($name, 1790467200);
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
}
if (!$zip->close()) { throw new RuntimeException('Cannot finish ZIP'); }
$hash = hash_file('sha256', $path);
file_put_contents($path . '.sha256', $hash . '  ' . basename($path) . "\n");
echo $path . "\n" . count($files) . " files; SHA-256 " . $hash . "\n";
