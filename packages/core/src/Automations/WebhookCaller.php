<?php

namespace Gerenuk\Nudge\Automations;

use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * Posts automation data to a customer's URL. Only public https addresses are allowed, so a
 * webhook step cannot be pointed at the servers running this platform. The connection is
 * pinned to the address that was checked, so DNS can't change between the check and the request.
 */
class WebhookCaller
{
    public function __construct(private readonly Http $http) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $url, array $payload): int
    {
        ['host' => $host, 'port' => $port, 'ip' => $ip] = $this->ensurePublic($url);

        $connectIp = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return $this->http
            ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$connectIp}"]]])
            ->timeout(10)
            ->acceptJson()
            ->withoutRedirecting()
            ->post($url, $payload)
            ->status();
    }

    /**
     * @return array{host: string, port: int, ip: string}
     */
    private function ensurePublic(string $url): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($host)) {
            throw new RuntimeException('Webhook URLs must use https.');
        }

        $host = trim($host, '[]');
        $port = $parts['port'] ?? 443;

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            throw new RuntimeException("Could not resolve {$host}.");
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException("{$host} points at a private address.");
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $addresses = [];

        foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
