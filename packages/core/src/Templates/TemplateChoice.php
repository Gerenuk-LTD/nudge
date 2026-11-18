<?php

namespace Gerenuk\Marketing\Templates;

use Gerenuk\Marketing\Models\EmailTemplate;
use Illuminate\Validation\ValidationException;

/**
 * The templates an email can start from, built-in starters and the workspace's own, each named by a key such
 * as `starter:newsletter` or `saved:12`.
 */
class TemplateChoice
{
    /**
     * Every template on offer, the workspace's own first, for a picker.
     *
     * @return list<array{key: string, name: string, description: string|null, subject: string|null, preheader: string|null, editor: string, editor_state: array<string, mixed>|null, html: string|null, saved: bool}>
     */
    public function options(): array
    {
        $saved = EmailTemplate::query()->latest('updated_at')->get()->map(fn (EmailTemplate $template) => [
            'key' => "saved:{$template->id}",
            'name' => $template->name,
            'description' => $template->description,
            'subject' => $template->subject,
            'preheader' => $template->preheader,
            'editor' => $template->editor,
            'editor_state' => $template->editor_state,
            'html' => $template->html,
            'saved' => true,
        ]);

        $starters = collect(StarterTemplates::all())->map(fn (array $starter) => [
            'key' => "starter:{$starter['key']}",
            'name' => $starter['name'],
            'description' => $starter['description'],
            'subject' => null,
            'preheader' => null,
            'editor' => $starter['editor'],
            'editor_state' => $starter['editor_state'],
            'html' => $starter['html'],
            'saved' => false,
        ]);

        return array_values($saved->concat($starters)->all());
    }

    /**
     * The content an email copies from the chosen template, or from the default starter when none was chosen.
     *
     * @return array{subject?: string|null, preheader?: string|null, editor: string, editor_state: array<string, mixed>|null, html: string|null}
     *
     * @throws ValidationException When the key names no template this workspace can use.
     */
    public function content(?string $key, string $attribute = 'template'): array
    {
        [$kind, $id] = array_pad(explode(':', $key ?? 'starter:'.StarterTemplates::DEFAULT, 2), 2, '');

        if ($kind === 'starter' && StarterTemplates::exists($id)) {
            return StarterTemplates::content($id);
        }

        $template = $kind === 'saved' && ctype_digit($id) ? EmailTemplate::query()->find((int) $id) : null;

        if ($template === null) {
            throw ValidationException::withMessages([$attribute => __('Choose one of the templates on offer.')]);
        }

        return $template->only(['subject', 'preheader', 'editor', 'editor_state', 'html']);
    }
}
