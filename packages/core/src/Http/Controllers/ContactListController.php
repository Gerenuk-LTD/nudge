<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactListController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(): Response
    {
        return Inertia::render('marketing/lists/index', [
            'lists' => ContactList::query()
                ->withCount(['contacts' => fn ($query) => $query->whereNull('archived_at')])
                ->orderBy('name')
                ->get(['id', 'name', 'description']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ContactList::create($this->validated($request));

        return to_route('marketing.lists.index');
    }

    public function update(Request $request, ContactList $list): RedirectResponse
    {
        $list->update($this->validated($request, $list));

        return to_route('marketing.lists.index');
    }

    public function destroy(ContactList $list): RedirectResponse
    {
        $list->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List deleted. Its contacts were kept.')]);

        return to_route('marketing.lists.index');
    }

    /**
     * @return array{name: string, description?: string|null}
     */
    private function validated(Request $request, ?ContactList $list = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('contact_lists', 'name')->where('workspace_id', $this->currentWorkspace->id())->ignore($list?->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
