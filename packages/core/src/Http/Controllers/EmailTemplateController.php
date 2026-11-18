<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Enums\CampaignKind;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\EmailTemplate;
use Gerenuk\Nudge\Support\MergeTagOptions;
use Gerenuk\Nudge\Templates\EmailEditor;
use Gerenuk\Nudge\Templates\StarterTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmailTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('nudge/templates/index', [
            'templates' => EmailTemplate::query()->latest('updated_at')->get()->map(fn (EmailTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'subject' => $template->subject,
                'editor' => $template->editor,
                'editor_state' => $template->editor_state,
                'html' => $template->html,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ]),
            'starters' => StarterTemplates::all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $starter = $request->string('starter')->toString();
        $starter = StarterTemplates::exists($starter) ? $starter : StarterTemplates::DEFAULT;

        $content = StarterTemplates::content($starter);

        return Inertia::render('nudge/templates/form', [
            'template' => null,
            'editor' => $content['editor'],
            'editorState' => $content['editor_state'],
            'html' => (string) $content['html'],
            'mergeTags' => MergeTagOptions::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $template = EmailTemplate::create([
            ...$this->validated($request),
            'created_by' => $this->user($request)->getAuthIdentifier(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return to_route('nudge.templates.edit', $template);
    }

    public function edit(EmailTemplate $template): Response
    {
        return Inertia::render('nudge/templates/form', [
            'template' => $template->only(['id', 'name', 'description', 'subject', 'preheader']),
            'editor' => $template->editor,
            'editorState' => $template->editor_state,
            'html' => $template->html,
            'mergeTags' => MergeTagOptions::all(),
        ]);
    }

    public function update(Request $request, EmailTemplate $template): RedirectResponse
    {
        $template->update($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return to_route('nudge.templates.edit', $template);
    }

    public function destroy(EmailTemplate $template): RedirectResponse
    {
        $template->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template deleted. Emails made from it are unchanged.')]);

        return to_route('nudge.templates.index');
    }

    public function duplicate(Request $request, EmailTemplate $template): RedirectResponse
    {
        $copy = $template->replicate();
        $copy->fill([
            'name' => __(':name (copy)', ['name' => $template->name]),
            'created_by' => $this->user($request)->getAuthIdentifier(),
        ])->save();

        return to_route('nudge.templates.edit', $copy);
    }

    /**
     * Saves a campaign's subject and content as a new template, from what was sent when it has gone out.
     */
    public function storeFromCampaign(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_if($campaign->kind === CampaignKind::Automation, 404);

        $validated = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $sent = $campaign->snapshot;

        $template = EmailTemplate::create([
            'name' => $validated['name'],
            'created_by' => $this->user($request)->getAuthIdentifier(),
            'subject' => $sent->subject ?? $campaign->subject,
            'preheader' => $sent->preheader ?? $campaign->preheader,
            'editor' => $campaign->editor,
            'editor_state' => $campaign->editor_state,
            'html' => (string) ($sent->html ?? $campaign->html),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved as a template.')]);

        return to_route('nudge.templates.edit', $template);
    }

    /**
     * @return array{name: string, description: string|null, subject: string|null, preheader: string|null, editor: string, editor_state: array<string, mixed>|null, html: string}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'editor' => ['required', Rule::in(EmailEditor::KEYS)],
            'editor_state' => ['nullable', 'array'],
            'html' => ['required', 'string', 'max:1000000'],
        ]);

        // A save replaces the whole template, so optional fields left out are cleared rather than kept.
        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'preheader' => $validated['preheader'] ?? null,
            'editor' => $validated['editor'],
            'editor_state' => $validated['editor_state'] ?? null,
            'html' => $validated['html'],
        ];
    }
}
