<?php
declare(strict_types=1);
namespace KumaMainWP;

/** The small subset of Socket.IO/Engine.IO v4 used by Kuma management. */
final class Socket
{
    private string $endpoint;
    private string $sid = '';
    private int $sequence = 0;
    private array $events = [];
    private array $acks = [];
    private array $cookies = [];
    private float $deadline;
    private int $received = 0;
    private bool $ready = false;

    public function __construct(array $settings)
    {
        $base = Settings::normalizeUrl((string) $settings['url'], !empty($settings['allow_http']));
        $this->endpoint = $base . '/socket.io/?EIO=4&transport=polling';
        $this->deadline = microtime(true) + 20;
        $body = $this->request('GET');
        $hello = str_starts_with($body, '0') ? json_decode(substr($body, 1), true) : null;
        if (!is_array($hello) || !is_string($hello['sid'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $hello['sid'])) {
            throw new \RuntimeException('Kuma did not return a valid management handshake. Check the URL and reverse proxy.');
        }
        $this->sid = $hello['sid'];
        try {
            $this->request('POST', '40');
            while (!$this->ready) { $this->receive($this->request('GET')); }
        }
        catch (\RuntimeException $e) { $this->close(); throw $e; }
    }

    public function call(string $event, array $args = []): array
    {
        $this->deadline = microtime(true) + 20;
        $this->received = 0;
        $id = ++$this->sequence;
        $payload = wp_json_encode(array_merge([$event], $args));
        if ($payload === false || strlen($payload) > 65536) { throw new \RuntimeException('The management request is invalid or too large.'); }
        $this->request('POST', '42' . $id . $payload);
        while (!isset($this->acks[$id])) { $this->receive($this->request('GET')); }
        $result = $this->acks[$id]; unset($this->acks[$id]);
        if (!empty($result['tokenRequired'])) { throw new \RuntimeException('Kuma requires a current two-factor authentication code.'); }
        if (($result['ok'] ?? false) !== true) {
            $message = match ($event) {
                'login' => 'Kuma sign-in failed. Check the username, password and current two-factor code.',
                'loginByToken' => 'The Kuma management session has expired or been revoked. Sign in again.',
                'add' => 'Kuma could not confirm the new monitor. Refresh the list and check Kuma before retrying.',
                default => 'Kuma could not complete the management request.',
            };
            throw new \RuntimeException($message);
        }
        return $result;
    }

    public function inventory(): array
    {
        unset($this->events['monitorList']);
        $this->call('getMonitorList');
        if (!isset($this->events['monitorList'], $this->events['notificationList'])) { throw new \RuntimeException('Kuma did not supply a complete monitor and notification list.'); }
        $monitors = [];
        foreach ($this->events['monitorList'] as $m) {
            if (!is_array($m) || !preg_match('/^[1-9][0-9]{0,15}$/D', (string) ($m['id'] ?? '')) || !is_string($m['name'] ?? null) || !is_string($m['type'] ?? null) || (isset($m['url']) && !is_string($m['url']))) {
                throw new \RuntimeException('Kuma returned an invalid monitor list. No monitors were added.');
            }
            $id = (string) $m['id'];
            $monitors[$id] = ['id'=>$id,'name'=>$m['name'],'url'=>$m['url'] ?? '','type'=>$m['type'],'active'=>!empty($m['active'])];
        }
        $notifications = [];
        foreach ($this->events['notificationList'] as $n) {
            if (!is_array($n) || !preg_match('/^[1-9][0-9]{0,15}$/D', (string) ($n['id'] ?? '')) || !is_string($n['name'] ?? null)) { throw new \RuntimeException('Kuma returned an invalid notification list.'); }
            $id = (string) $n['id'];
            $notifications[$id] = ['id'=>$id,'name'=>$n['name'],'active'=>!empty($n['active']),'default'=>!empty($n['isDefault'])];
        }
        return ['monitors'=>$monitors,'notifications'=>$notifications];
    }

    public function close(): void
    {
        if ($this->sid !== '') {
            $this->deadline = microtime(true) + 2;
            try { $this->request('POST', '1'); } catch (\RuntimeException $e) { /* Best-effort session cleanup. */ }
            $this->sid = '';
        }
        $this->events = []; $this->acks = []; $this->cookies = [];
    }

    private function receive(string $body): void
    {
        foreach (explode("\x1e", $body) as $packet) {
            if ($packet === '2') { $this->request('POST', '3'); }
            elseif ($packet === '1' || $packet === '41' || str_starts_with($packet, '44')) { throw new \RuntimeException('Kuma closed the management connection. Sign in again.'); }
            elseif (preg_match('/^43([0-9]+)(\[.*)$/s', $packet, $matches)) {
                $data = json_decode($matches[2], true);
                if (!is_array($data) || !is_array($data[0] ?? null)) { throw new \RuntimeException('Kuma returned an invalid management acknowledgement.'); }
                $this->acks[(int) $matches[1]] = $data[0];
            } elseif (str_starts_with($packet, '42')) {
                $data = json_decode(substr($packet, 2), true);
                if (!is_array($data) || !is_string($data[0] ?? null)) { throw new \RuntimeException('Kuma returned invalid management data.'); }
                if (in_array($data[0], ['loginRequired','autoLogin'], true)) { $this->ready = true; }
                if (in_array($data[0], ['monitorList','notificationList'], true)) {
                    if (!is_array($data[1] ?? null)) { throw new \RuntimeException('Kuma returned an incomplete management list.'); }
                    $this->events[$data[0]] = $data[1];
                }
            } elseif ($packet !== '6' && !str_starts_with($packet, '40')) { throw new \RuntimeException('Unsupported Kuma management protocol.'); }
        }
    }

    private function request(string $method, string $body = ''): string
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) { throw new \RuntimeException('The Kuma management request timed out. Refresh the list before trying again.'); }
        $url = $this->endpoint . ($this->sid === '' ? '' : '&sid=' . rawurlencode($this->sid));
        $response = wp_remote_request($url, ['method'=>$method,'body'=>$body,'headers'=>['Content-Type'=>'text/plain;charset=UTF-8','Accept'=>'*/*'],'cookies'=>$this->cookies,'timeout'=>min(10, $remaining),'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>false,'limit_response_size'=>5242881]);
        if (is_wp_error($response)) { throw new \RuntimeException('Could not reach Kuma management. Check the connection, then refresh the list before retrying.'); }
        if (wp_remote_retrieve_response_code($response) !== 200) { throw new \RuntimeException('Kuma management returned HTTP ' . wp_remote_retrieve_response_code($response) . '. Redirects are not followed.'); }
        $data = wp_remote_retrieve_body($response);
        $this->received += strlen($data);
        if (strlen($data) > 5242880 || $this->received > 20971520) { throw new \RuntimeException('The Kuma management response exceeded the size limit.'); }
        $cookies = wp_remote_retrieve_cookies($response);
        if ($cookies) { $this->cookies = $cookies; }
        return $data;
    }
}
