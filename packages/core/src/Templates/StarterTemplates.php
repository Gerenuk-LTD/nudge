<?php

namespace Gerenuk\Nudge\Templates;

/**
 * The built-in designs every workspace can start an email from, kept as files in the package: drag-and-drop
 * documents as JSON, and one hand-written HTML email for people who would rather write code.
 */
class StarterTemplates
{
    /** The design new campaigns get when no template is chosen. */
    public const DEFAULT = 'simple';

    /**
     * @return array<string, array{name: string, description: string, editor: string}>
     */
    public static function catalog(): array
    {
        return [
            'simple' => ['name' => __('Simple'), 'description' => __('A greeting, your message and the footer.'), 'editor' => EmailEditor::BLOCKS],
            'newsletter' => ['name' => __('Newsletter'), 'description' => __('A header and a few short stories with links.'), 'editor' => EmailEditor::BLOCKS],
            'announcement' => ['name' => __('Announcement'), 'description' => __('One centred message with a button.'), 'editor' => EmailEditor::BLOCKS],
            'custom' => ['name' => __('Custom HTML'), 'description' => __('Write the email in HTML yourself.'), 'editor' => EmailEditor::HTML],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::catalog());
    }

    /**
     * What an email copies from a starter. Builder starters have no HTML yet: the builder renders it when the email
     * is first opened.
     *
     * @return array{editor: string, editor_state: array<string, mixed>|null, html: string|null}
     */
    public static function content(string $key): array
    {
        $editor = self::catalog()[$key]['editor'];

        if ($editor === EmailEditor::BLOCKS) {
            return ['editor' => $editor, 'editor_state' => self::read("{$key}.json", decode: true), 'html' => null];
        }

        return ['editor' => $editor, 'editor_state' => null, 'html' => self::read("{$key}.html")];
    }

    /**
     * @return list<array{key: string, name: string, description: string, editor: string, editor_state: array<string, mixed>|null, html: string|null}>
     */
    public static function all(): array
    {
        return array_values(collect(self::catalog())
            ->map(fn (array $starter, string $key) => ['key' => $key, 'name' => $starter['name'], 'description' => $starter['description'], ...self::content($key)])
            ->all());
    }

    /**
     * @return ($decode is true ? array<string, mixed> : string)
     */
    private static function read(string $file, bool $decode = false): array|string
    {
        $contents = (string) file_get_contents(__DIR__."/../../resources/email-templates/{$file}");

        return $decode ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : $contents;
    }
}
