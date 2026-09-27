<?php
declare(strict_types=1);
namespace KumaMainWP;

/** Parses the subset of Prometheus exposition supplied by Uptime Kuma. */
final class Metrics
{
    private const NAMES = ['monitor_status', 'monitor_response_time', 'monitor_uptime_ratio', 'monitor_response_time_seconds', 'monitor_cert_days_remaining'];

    public static function parse(string $text): array
    {
        $monitors = [];
        $recognized = false;
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            if ($line[0] === '#') {
                if (preg_match('/^# (?:HELP|TYPE) monitor_status\s/', $line)) { $recognized = true; }
                continue;
            }
            if (!preg_match('/^(monitor_[a-z_]+)(?:\{|\s)/', $line, $prefix) || !in_array($prefix[1], self::NAMES, true)) { continue; }
            if (!preg_match('/^(monitor_[a-z_]+)\{(.*)\}\s+([^\s]+)(?:\s+\d+)?$/D', $line, $sample)) {
                throw new \RuntimeException('Invalid Kuma metrics response.');
            }
            $labels = self::labels($sample[2]);
            $id = $labels['monitor_id'] ?? '';
            if (!preg_match('/^[1-9][0-9]{0,15}$/D', $id)) {
                throw new \RuntimeException('Metrics need stable monitor IDs. Use Uptime Kuma 2.5.5 or a compatible version.');
            }
            $recognized = true;
            $monitors[$id] ??= ['id'=>$id, 'name'=>'Monitor ' . $id, 'url'=>'', 'type'=>'', 'status'=>null, 'response_ms'=>null, 'cert_days'=>null, 'uptime'=>[], 'average_ms'=>[]];
            $monitor = &$monitors[$id];
            foreach (['monitor_name'=>'name','monitor_url'=>'url','monitor_type'=>'type'] as $source=>$target) {
                if (isset($labels[$source]) && ($sample[1] === 'monitor_status' || $monitor[$target] === '' || $monitor[$target] === 'Monitor ' . $id)) { $monitor[$target] = $labels[$source]; }
            }
            $raw = $sample[3];
            if (in_array($raw, ['NaN', '+Inf', '-Inf', 'Inf'], true)) { unset($monitor); continue; }
            if (!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $raw)) {
                throw new \RuntimeException('Invalid numeric value in Kuma metrics.');
            }
            $value = (float) $raw;
            if (!is_finite($value)) { unset($monitor); continue; }
            switch ($sample[1]) {
                case 'monitor_status':
                    $monitor['status'] = in_array($value, [0.0,1.0,2.0,3.0], true) ? (int) $value : null;
                    break;
                case 'monitor_response_time':
                    $monitor['response_ms'] = $value >= 0 ? $value : null;
                    break;
                case 'monitor_cert_days_remaining':
                    $monitor['cert_days'] = $value;
                    break;
                default:
                    $window = $labels['window'] ?? '';
                    if (!in_array($window, ['1d','30d','365d'], true)) { break; }
                    if ($sample[1] === 'monitor_uptime_ratio' && $value >= 0 && $value <= 1) { $monitor['uptime'][$window] = $value; }
                    if ($sample[1] === 'monitor_response_time_seconds' && $value >= 0 && is_finite($value * 1000)) { $monitor['average_ms'][$window] = $value * 1000; }
            }
            unset($monitor);
        }
        if (!$recognized) { throw new \RuntimeException('The endpoint did not return Uptime Kuma metrics. Check the base URL and API key.'); }
        return $monitors;
    }

    private static function labels(string $input): array
    {
        $labels = [];
        $offset = 0;
        $length = strlen($input);
        // Prometheus supports exactly three string escapes: backslash, quote, newline.
        $pattern = '/\G\s*([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\[\\\\"n])*)"\s*(,|$)/';
        while ($offset < $length) {
            if (!preg_match($pattern, $input, $match, 0, $offset) || isset($labels[$match[1]])) {
                throw new \RuntimeException('Invalid labels in Kuma metrics.');
            }
            $labels[$match[1]] = strtr($match[2], ['\\n'=>"\n", '\\"'=>'"', '\\\\'=>'\\']);
            $offset += strlen($match[0]);
        }
        return $labels;
    }
}
