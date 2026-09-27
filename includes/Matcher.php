<?php
declare(strict_types=1);
namespace KumaMainWP;

final class Matcher
{
    public static function find(array $site, array $monitors, array $mappings): array
    {
        $mapping = $mappings[(string) ($site['id'] ?? '')] ?? '';
        if ($mapping === 'none') { return ['monitor'=>null, 'reason'=>'disabled']; }
        if ($mapping !== '') {
            return ['monitor'=>$monitors[$mapping] ?? null, 'reason'=>isset($monitors[$mapping]) ? 'manual' : 'missing'];
        }
        $url = self::url((string) ($site['url'] ?? ''));
        $matches = [];
        if ($url !== null) {
            foreach ($monitors as $monitor) {
                if (self::url((string) ($monitor['url'] ?? '')) === $url) { $matches[] = $monitor; }
            }
        }
        return count($matches) === 1
            ? ['monitor'=>$matches[0], 'reason'=>'automatic']
            : ['monitor'=>null, 'reason'=>count($matches) > 1 ? 'ambiguous' : 'unmatched'];
    }

    private static function url(string $value): ?string
    {
        $parts = parse_url(trim($value));
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || isset($parts['user']) || isset($parts['pass'])) { return null; }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = $parts['path'] ?? '/';
        return $scheme . '://' . strtolower($parts['host']) . ':' . $port . ($path === '' ? '/' : $path) . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
