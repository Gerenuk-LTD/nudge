<?php

namespace Gerenuk\Nudge\Sending;

/**
 * Finds trackable links in email HTML: absolute http(s) hrefs without merge tags.
 */
class LinkExtractor
{
    private const HREF = '#(<a\b[^>]*?\bhref\s*=\s*)(["\'])(https?://[^"\']+)\2#i';

    /**
     * @return list<string> Unique URLs, decoded from HTML entities
     */
    public static function urls(string $html): array
    {
        preg_match_all(self::HREF, $html, $matches);

        $urls = array_map(fn (string $url) => html_entity_decode($url), $matches[3]);

        return array_values(array_unique(array_filter($urls, fn (string $url) => ! str_contains($url, '{{'))));
    }

    /**
     * Replace each trackable href with the URL the callback returns, or leave it when the callback returns null.
     *
     * @param  callable(string): ?string  $replace  Receives the decoded original URL
     */
    public static function rewrite(string $html, callable $replace): string
    {
        return (string) preg_replace_callback(self::HREF, function (array $match) use ($replace): string {
            $tracked = $replace(html_entity_decode($match[3]));

            return $tracked === null ? $match[0] : $match[1].$match[2].e($tracked).$match[2];
        }, $html);
    }

    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
