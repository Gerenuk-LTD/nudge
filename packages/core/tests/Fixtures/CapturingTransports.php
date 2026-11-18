<?php

namespace Gerenuk\Nudge\Tests\Fixtures;

use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Sending\ProviderTransports;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Captures every message instead of sending it. Addresses in $failFor are rejected.
 */
class CapturingTransports extends ProviderTransports
{
    /** @var list<Email> */
    public array $sent = [];

    /** @var list<string> */
    public array $failFor = [];

    public function for(Provider $provider): TransportInterface
    {
        return $this->transport();
    }

    public function fromDsn(string $dsn): TransportInterface
    {
        return $this->transport();
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_map(fn (Email $email) => $email->getTo()[0]->getAddress(), $this->sent);
    }

    private function transport(): TransportInterface
    {
        $capture = $this;

        return new class($capture) extends AbstractTransport
        {
            public function __construct(private readonly CapturingTransports $capture)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                $email = MessageConverter::toEmail($message->getOriginalMessage());

                if (in_array($email->getTo()[0]->getAddress(), $this->capture->failFor, true)) {
                    throw new \RuntimeException('Rejected by the provider.');
                }

                $this->capture->sent[] = $email;
                $message->setMessageId('provider-'.count($this->capture->sent));
            }

            public function __toString(): string
            {
                return 'capturing://';
            }
        };
    }
}
