<?php
declare(strict_types=1);
namespace KumaMainWP;

final class Presentation
{
    public const TOKENS = [
        '[kuma.status]'=>'Current monitor status',
        '[kuma.response_ms]'=>'Latest response time in milliseconds',
        '[kuma.uptime.1d]'=>'Rolling 24-hour uptime',
        '[kuma.uptime.30d]'=>'Rolling 30-day uptime',
        '[kuma.uptime.365d]'=>'Rolling 365-day uptime',
        '[kuma.checked_at]'=>'Last successful metrics collection (UTC)',
    ];

    public static function status(?int $status): string
    {
        return [0=>'Down',1=>'Up',2=>'Pending',3=>'Maintenance'][$status ?? -1] ?? 'Unknown';
    }

    public static function percent(?float $value): string
    {
        return $value === null ? 'Unavailable' : number_format($value * 100, 2, '.', '') . '%';
    }

    public static function reason(string $reason): string
    {
        return ['disabled'=>'Disabled','missing'=>'Mapped monitor missing','ambiguous'=>'Choose a monitor','unmatched'=>'No matching monitor'][$reason] ?? $reason;
    }

    public static function column(array $site, array $state, array $mappings): string
    {
        $match = Matcher::find($site, $state['monitors'], $mappings);
        if (!$match['monitor']) { return '<span class="kmw-muted">' . esc_html(self::reason($match['reason'])) . '</span>'; }
        $m = $match['monitor'];
        if (Poller::stale($state)) { return '<span class="kmw-badge kmw-stale">Stale</span><br><small>Last known: ' . esc_html(self::status($m['status'])) . '</small>'; }
        $label = self::status($m['status']);
        $html = '<span class="kmw-badge kmw-' . esc_attr(strtolower($label)) . '">' . esc_html($label) . '</span>';
        $html .= '<br><small>' . esc_html(($m['response_ms'] === null ? 'Unavailable' : number_format($m['response_ms'], 0) . ' ms') . ' · 24h ' . self::percent($m['uptime']['1d'] ?? null)) . '</small>';
        $url = Settings::get()['url'];
        if ($url !== '') { $html = '<a href="' . esc_url($url . '/dashboard/' . rawurlencode($m['id'])) . '" target="_blank" rel="noopener noreferrer" title="Open monitor in Kuma">' . $html . '</a>'; }
        return '<span class="kmw-cell">' . $html . '</span>';
    }

    public static function tokens(array $site, array $state, array $mappings): array
    {
        $tokens = array_fill_keys(array_keys(self::TOKENS), 'Unavailable');
        $match = Matcher::find($site, $state['monitors'], $mappings);
        if (!$match['monitor']) { return $tokens; }
        $tokens['[kuma.checked_at]'] = !empty($state['success_at']) ? gmdate('Y-m-d H:i:s', $state['success_at']) . ' UTC' : 'Unavailable';
        if (Poller::stale($state)) {
            foreach ($tokens as $key => $value) { if ($key !== '[kuma.checked_at]') { $tokens[$key] = 'Unavailable (Kuma data stale)'; } }
            return $tokens;
        }
        $m = $match['monitor'];
        $tokens['[kuma.status]'] = self::status($m['status']);
        $tokens['[kuma.response_ms]'] = $m['response_ms'] === null ? 'Unavailable' : number_format($m['response_ms'], 0, '.', '');
        foreach (['1d','30d','365d'] as $window) { $tokens['[kuma.uptime.' . $window . ']'] = self::percent($m['uptime'][$window] ?? null); }
        return $tokens;
    }
}
