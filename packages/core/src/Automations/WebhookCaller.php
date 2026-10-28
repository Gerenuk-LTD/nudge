<?php

namespace Gerenuk\Marketing\Automations;

use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * Posts automation data to a customer's URL. Only public https addresses are allowed, so a
 * webhook step cannot be pointed at the servers running this platform.
 */
class WebhookCaller
{
    public function __construct(private readonly Http $http) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $url, array $payload): int
    {
        $this->ensurePublic($url);

        return $this->http->timeout(10)->acceptJson()->withoutRedirecting()->post($url, $payload)->status();
    }

    private function ensurePublic(string $url): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($host)) {
            throw new RuntimeException('Webhook URLs must use https.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            throw new RuntimeException("Could not resolve {$host}.");
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException("{$host} points at a private address.");
            }
        }
    }
}
