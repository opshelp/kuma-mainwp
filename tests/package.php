<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/scripts/release-files.json'), true, 512, JSON_THROW_ON_ERROR);
$sandbox = sys_get_temp_dir() . '/kmw-package-' . bin2hex(random_bytes(8));
mkdir($sandbox, 0700);
foreach (array_merge($manifest['plugin'], $manifest['source']) as $file) {
    $target = $sandbox . '/' . $file;
    if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
    copy($root . '/' . $file, $target);
}
register_shutdown_function(static function () use ($sandbox): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($sandbox);
});
function build(bool $source = false): array {
    global $sandbox;
    $args = [PHP_BINARY, $sandbox . '/scripts/package.php'];
    if ($source) { $args[] = '--source'; }
    $process = proc_open($args, [1=>['pipe','w'],2=>['pipe','w']], $pipes, $sandbox);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

test('release archives exclude unlisted files, local data and Git history', function () use ($sandbox): void {
    $canary = 'PRIVATE PACKAGE CANARY ' . bin2hex(random_bytes(16));
    foreach (['includes/.env','includes/unlisted-secret.key','assets/debug.log','.dev/credentials.json','.git/config','dist/old.zip'] as $file) {
        if (!is_dir(dirname($sandbox . '/' . $file))) { mkdir(dirname($sandbox . '/' . $file), 0700, true); }
        file_put_contents($sandbox . '/' . $file, $canary);
    }
    foreach ([false, true] as $source) {
        [$status,$out,$err] = build($source); same(0,$status);
        $path = strtok($out,"\n");$zip = new ZipArchive();same(true,$zip->open($path));
        for ($i=0;$i<$zip->numFiles;++$i) {
            $name=$zip->getNameIndex($i);
            truth(!str_contains($zip->getFromIndex($i),$canary),'Private data in archive');
            truth(!preg_match('~/(?:\.git|\.dev|dist)/~',$name),'Development path in archive');
            if (!$source) { truth(!preg_match('~/(?:tests|scripts|\.github)/~',$name),'Development tool in plugin'); }
        }
        truth($zip->locateName('kuma-mainwp/includes/Lock.php') !== false);
        truth($zip->locateName('kuma-mainwp/SECURITY.md') !== false);
        same($source, $zip->locateName('kuma-mainwp/.github/workflows/ci.yml') !== false);
        $zip->close();
        same(hash_file('sha256',$path),explode(' ',file_get_contents($path.'.sha256'))[0]);
        $before=hash_file('sha256',$path);same(0,build($source)[0]);same($before,hash_file('sha256',$path));
    }
});
test('release packaging rejects symlinked files and directories', function () use ($sandbox): void {
    $file=$sandbox.'/includes/Client.php';$contents=file_get_contents($file);
    unlink($file);symlink($sandbox.'/LICENSE',$file);
    try { [$status,$out,$err]=build();truth($status !== 0);truth(str_contains($err,'Symlinked release path')); }
    finally { unlink($file);file_put_contents($file,$contents); }
    rename($sandbox.'/includes',$sandbox.'/real-includes');symlink($sandbox.'/real-includes',$sandbox.'/includes');
    try { [$status,$out,$err]=build();truth($status !== 0);truth(str_contains($err,'Symlinked release path')); }
    finally { unlink($sandbox.'/includes');rename($sandbox.'/real-includes',$sandbox.'/includes'); }
});
test('version metadata agrees between plugin, constant and readme', function () use ($root): void {
    $header=file_get_contents($root.'/kuma-mainwp.php');
    preg_match('/Version:\s*(\d+\.\d+\.\d+)/',$header,$version);
    truth(str_contains($header,"define('KMW_VERSION', '".$version[1]."')"));
    truth(str_contains(file_get_contents($root.'/readme.txt'),'Stable tag: '.$version[1]));
});
run_tests();
