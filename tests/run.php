<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use KumaMainWP\Metrics;
use KumaMainWP\Matcher;

test('parses Kuma labels, windows and unit conversions', function (): void {
    $m = Metrics::parse(file_get_contents(__DIR__ . '/fixtures/kuma.prom'));
    same(2, count($m));
    same("Client \"A\" \\ portal\nProduction", $m[7]['name']);
    same(1, $m[7]['status']);
    same(125.0, $m[7]['response_ms']);
    same(0.9995, $m[7]['uptime']['1d']);
    same(0.0, $m[7]['uptime']['30d']);
    truth(!isset($m[7]['uptime']['365d']));
    same(250.0, $m[7]['average_ms']['1d']);
    same(42.0, $m[7]['cert_days']);
    same(3, $m[8]['status']);
    same(null, $m[8]['response_ms']);
});
test('metric order does not overwrite identity with absent labels', function (): void {
    $m = Metrics::parse("monitor_response_time{monitor_id=\"9\"} 20\nmonitor_status{monitor_name=\"Nine\",monitor_id=\"9\",monitor_url=\"https://nine.test\"} 0\n");
    same('Nine', $m[9]['name']); same(0, $m[9]['status']);
});
test('rejects HTML, incomplete metrics and old IDs', function (): void {
    foreach (['<html>login</html>', 'nodejs_heap_size_total_bytes 20', 'monitor_status{monitor_id="7",bad} 1', 'monitor_status{monitor_name="Old"} 1'] as $input) {
        throws(fn() => Metrics::parse($input));
    }
});
test('empty Kuma registry is a valid empty monitor list', function (): void {
    same([], Metrics::parse("# HELP monitor_status Monitor Status\n# TYPE monitor_status gauge\n"));
});
test('unknown status and out-of-range ratios stay unavailable', function (): void {
    $m = Metrics::parse("monitor_status{monitor_id=\"1\"} 9\nmonitor_uptime_ratio{monitor_id=\"1\",window=\"1d\"} 1.1\nmonitor_response_time{monitor_id=\"1\"} +Inf\n");
    same(null, $m[1]['status']); same([], $m[1]['uptime']); same(null, $m[1]['response_ms']);
});
test('normalizes root URL without merging distinct sites', function (): void {
    $m = [7 => ['id'=>'7', 'url'=>'https://EXAMPLE.com:443/']];
    same('7', Matcher::find(['id'=>2,'url'=>'https://example.com'], $m, [])['monitor']['id']);
    foreach (['http://example.com','https://www.example.com','https://example.com/shop','https://example.com/?q=1'] as $url) {
        same(null, Matcher::find(['id'=>2,'url'=>$url], $m, [])['monitor']);
    }
});
test('duplicate URLs require an explicit mapping', function (): void {
    $m = [7=>['id'=>'7','url'=>'https://example.com/'],8=>['id'=>'8','url'=>'https://example.com/']];
    $site = ['id'=>2,'url'=>'https://example.com/'];
    same('ambiguous', Matcher::find($site,$m,[])['reason']);
    same('8', Matcher::find($site,$m,[2=>'8'])['monitor']['id']);
    same('disabled', Matcher::find($site,$m,[2=>'none'])['reason']);
    same('missing', Matcher::find($site,$m,[2=>'999'])['reason']);
});
test('invalid and empty URLs cannot auto-match', function (): void {
    foreach (['','javascript:alert(1)','not a url'] as $url) {
        same(null, Matcher::find(['id'=>2,'url'=>$url],[7=>['id'=>'7','url'=>$url]],[])['monitor']);
    }
});
test('a terminal DNS dot cannot bypass related-host duplicate review', function (): void {
    $sites=[['id'=>1,'url'=>'https://example.com/']];
    $candidates=\KumaMainWP\Provisioner::candidates($sites,[9=>['id'=>'9','url'=>'https://example.com./']],[]);
    same(false,$candidates[1]['eligible']);same('related',$candidates[1]['reason']);
});
run_tests();
