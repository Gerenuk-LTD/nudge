<?php

namespace Gerenuk\Marketing\Sending;

/**
 * A readable plain-text version of an HTML email, used when the campaign has no text of its own.
 */
class HtmlToText
{
    public static function convert(string $html): string
    {
        $html = (string) preg_replace('#<(head|style|script)\b[^>]*>.*?</\1>#is', '', $html);

        // Keep links readable: "text (url)", or just the url when the text is the url.
        $html = (string) preg_replace_callback('#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function (array $match): string {
            $url = html_entity_decode($match[2]);
            $text = trim(strip_tags($match[3]));

            return $text === '' || $text === $url ? $url : "{$text} ({$url})";
        }, $html);

        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(p|div|h[1-6]|li|tr|table|blockquote)>#i', "\n\n", $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', '- ', $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
