<?php
declare(strict_types=1);
namespace KumaMainWP;

final class Client
{
    private const MAX_BYTES = 5242880;

    public static function fetch(array $settings, string $key): array
    {
        $base = Settings::normalizeUrl($settings['url'], (bool) $settings['allow_http']);
        if ($key === '') { throw new \RuntimeException('Enter a Kuma metrics API key before connecting.'); }
        $response = wp_remote_get($base . '/metrics', [
            'timeout'=>10, 'redirection'=>0, 'sslverify'=>true,
            // This administrator-configured endpoint may intentionally be on a private network.
            'reject_unsafe_urls'=>false, 'limit_response_size'=>self::MAX_BYTES + 1,
            'headers'=>['Authorization'=>'Basic ' . base64_encode(':' . $key), 'Accept'=>'text/plain'],
        ]);
        if (is_wp_error($response)) { throw new \RuntimeException('Cannot reach Kuma. Check network access, the URL and its TLS certificate.'); }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new \RuntimeException('Kuma returned HTTP ' . (int) $code . '. ' . ($code >= 300 && $code < 400 ? 'Redirects are disabled; enter the final base URL.' : 'Check the API key and endpoint.'));
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > self::MAX_BYTES) { throw new \RuntimeException('Kuma metrics exceed the 5 MB response limit.'); }
        return Metrics::parse($body);
    }
}
