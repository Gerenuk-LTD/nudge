<?php

namespace Gerenuk\Marketing\Support;

use Gerenuk\Marketing\Enums\ContactStatus;

/**
 * Validated contact input. Tracks which fields were provided, because an upsert
 * only changes what the caller sent while an edit replaces everything.
 */
final class ContactData
{
    /**
     * @param  array<string, mixed>|null  $customFields
     * @param  list<string>  $tags
     * @param  list<int>  $lists
     * @param  list<string>  $provided
     */
    public function __construct(
        public readonly string $email,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?ContactStatus $status = null,
        public readonly ?array $customFields = null,
        public readonly array $tags = [],
        public readonly array $lists = [],
        private readonly array $provided = [],
    ) {}

    /**
     * Build from data that has passed ContactRules.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $customFields = $validated['custom_fields'] ?? null;
        $tags = $validated['tags'] ?? [];
        $lists = $validated['lists'] ?? [];
        $status = $validated['status'] ?? null;

        return new self(
            email: (string) $validated['email'],
            firstName: isset($validated['first_name']) ? (string) $validated['first_name'] : null,
            lastName: isset($validated['last_name']) ? (string) $validated['last_name'] : null,
            status: match (true) {
                $status instanceof ContactStatus => $status,
                is_string($status) => ContactStatus::from($status),
                default => null,
            },
            customFields: is_array($customFields) ? $customFields : null,
            tags: is_array($tags) ? array_values(array_map(strval(...), $tags)) : [],
            lists: is_array($lists) ? array_values(array_map(intval(...), $lists)) : [],
            provided: array_keys($validated),
        );
    }

    public function has(string $field): bool
    {
        return in_array($field, $this->provided, true);
    }

    public function withList(int $listId): self
    {
        return new self(
            $this->email, $this->firstName, $this->lastName, $this->status, $this->customFields,
            $this->tags, array_values(array_unique([...$this->lists, $listId])), array_values(array_unique([...$this->provided, 'lists'])),
        );
    }
}
