<?php

namespace Gerenuk\Marketing\Sending;

use Gerenuk\Marketing\Models\Contact;

/**
 * Replaces tags like {{ first_name }}, {{ custom.plan }} or {{ first_name | there }} (with a fallback).
 */
class MergeTags
{
    private const PATTERN = '/\{\{\s*([a-z][a-z0-9_.]*)\s*(?:\|\s*([^}]*?))?\s*\}\}/i';

    /**
     * @param  array<string, string>  $extra  Values for non-contact tags such as unsubscribe_url
     */
    public static function render(string $template, Contact $contact, array $extra, bool $escapeHtml): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $match) use ($contact, $extra, $escapeHtml): string {
            $tag = strtolower($match[1]);
            $value = $extra[$tag] ?? self::contactValue($contact, $tag);
            $value = $value === null || $value === '' ? trim($match[2] ?? '') : $value;

            // Extra values are URLs and addresses we built; contact values come from people and are escaped.
            return $escapeHtml && ! array_key_exists($tag, $extra) ? e($value) : $value;
        }, $template);
    }

    /**
     * @return list<string>
     */
    public static function tagsIn(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    private static function contactValue(Contact $contact, string $tag): ?string
    {
        if (str_starts_with($tag, 'custom.')) {
            $value = ($contact->custom_fields ?? [])[substr($tag, 7)] ?? null;

            return is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? (string) $value : null);
        }

        return match ($tag) {
            'email' => $contact->email,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'full_name' => trim("{$contact->first_name} {$contact->last_name}") ?: null,
            default => null,
        };
    }
}
