<?php

namespace Gerenuk\Marketing\Sending;

use Gerenuk\Marketing\Models\Provider;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the Symfony Mailer transport for a provider. Bound in the container so tests can swap it.
 */
class ProviderTransports
{
    /** @var array<int, TransportInterface> */
    private array $transports = [];

    public function for(Provider $provider): TransportInterface
    {
        return $this->transports[$provider->id] ??= Transport::fromDsn($provider->dsn());
    }

    /**
     * Build a transport from unsaved settings, to test them before saving.
     */
    public function fromDsn(string $dsn): TransportInterface
    {
        return Transport::fromDsn($dsn);
    }
}
