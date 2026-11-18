<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Tag;
use Gerenuk\Nudge\Support\TagReferences;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly TagReferences $references,
    ) {}

    public function index(): Response
    {
        $tags = Tag::query()
            ->withCount(['contacts' => fn ($query) => $query->whereNull('archived_at')])
            ->orderBy('name')
            ->get(['id', 'name']);

        $usage = $this->references->usage($tags->pluck('name', 'id')->all());

        return Inertia::render('nudge/tags/index', [
            'tags' => $tags->map(fn (Tag $tag) => [
                ...$tag->only(['id', 'name', 'contacts_count']),
                'used_by' => $usage[$tag->id],
            ]),
            'activeContacts' => Contact::query()->active()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Tag::create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag created.')]);

        return to_route('nudge.tags.index');
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $from = $tag->name;

        DB::transaction(function () use ($request, $tag, $from): void {
            $tag->update($this->validated($request, $tag));
            $this->references->renamed($from, $tag->name);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag renamed.')]);

        return to_route('nudge.tags.index');
    }

    /**
     * Fold one tag into another, for duplicates such as "VIP" and "vip".
     */
    public function merge(Request $request, Tag $tag): RedirectResponse
    {
        $validated = $request->validate([
            'into' => ['required', 'integer', Rule::notIn([$tag->id]), Rule::exists('tags', 'id')->where('workspace_id', $this->currentWorkspace->id())],
        ]);

        $into = Tag::query()->whereKey($validated['into'])->firstOrFail();
        $this->references->merge($tag, $into);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Merged :from into :into.', ['from' => $tag->name, 'into' => $into->name])]);

        return to_route('nudge.tags.index');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $tag->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag deleted.')]);

        return to_route('nudge.tags.index');
    }

    /**
     * @return array{name: string}
     */
    private function validated(Request $request, ?Tag $tag = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('tags', 'name')->where('workspace_id', $this->currentWorkspace->id())->ignore($tag?->id),
            ],
        ], [
            'name.unique' => __('There is already a tag with this name. To combine them, merge this tag instead.'),
        ]);
    }
}
