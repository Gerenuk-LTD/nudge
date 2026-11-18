<?php

namespace Gerenuk\Nudge\Sending;

/**
 * One credential a provider needs, described for the setup form.
 */
final class CredentialField
{
    /**
     * @param  list<array{value: string, label: string}>  $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $secret = false,
        public readonly bool $required = true,
        public readonly ?string $default = null,
        public readonly array $options = [],
        public readonly ?string $help = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'secret' => $this->secret,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options,
            'help' => $this->help,
        ];
    }
}
