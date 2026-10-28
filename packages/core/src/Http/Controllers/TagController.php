<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Models\Tag;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('marketing/tags/index', [
            'tags' => Tag::query()->withCount('contacts')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Tag $tag, CurrentWorkspace $currentWorkspace): RedirectResponse
    {
        $tag->update($request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('tags', 'name')->where('workspace_id', $currentWorkspace->id())->ignore($tag->id),
            ],
        ]));

        return to_route('marketing.tags.index');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $tag->delete();

        return to_route('marketing.tags.index');
    }
}
