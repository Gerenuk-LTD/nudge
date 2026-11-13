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

    /** Tags filled for every email without a custom field: contact details, then the ones sending supplies. */
    public const BUILT_IN = ['email', 'first_name', 'last_name', 'full_name', 'unsubscribe_url', 'postal_address'];

    /**
     * Tags in the template that nothing will fill and that have no fallback, so they'd come out blank.
     *
     * @param  list<string>  $customKeys  The workspace's custom field keys
     * @return list<string>
     */
    public static function unfilledIn(string $template, array $customKeys): array
    {
        preg_match_all(self::PATTERN, $template, $matches, PREG_SET_ORDER);
        $unfilled = [];

        foreach ($matches as $match) {
            $tag = strtolower($match[1]);
            $hasFallback = trim($match[2] ?? '') !== '';
            $known = in_array($tag, self::BUILT_IN, true)
                || (str_starts_with($tag, 'custom.') && in_array(substr($tag, 7), $customKeys, true));

            if (! $known && ! $hasFallback) {
                $unfilled[] = $tag;
            }
        }

        return array_values(array_unique($unfilled));
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
