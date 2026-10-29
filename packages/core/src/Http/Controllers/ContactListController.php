<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Support\DailySeries;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactListController extends Controller
{
    private const GROWTH_DAYS = 30;

    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(): Response
    {
        $lists = ContactList::query()
            ->withCount([
                'contacts' => fn ($query) => $query->whereNull('archived_at'),
                'contacts as subscribed_count' => fn ($query) => $query->whereNull('archived_at')->where('status', ContactStatus::Subscribed),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        // Memberships only record when a contact joined, so this is joins per day rather than net growth.
        $joins = DailySeries::countBy(
            DB::table('contact_list_memberships')->whereIn('contact_list_id', $lists->modelKeys()),
            'created_at',
            'contact_list_id',
            $lists->modelKeys(),
            self::GROWTH_DAYS,
        );

        return Inertia::render('marketing/lists/index', [
            'lists' => $lists->map(fn (ContactList $list) => [
                ...$list->only(['id', 'name', 'description', 'contacts_count', 'subscribed_count']),
                'joins' => $joins[$list->id],
            ]),
            'days' => self::GROWTH_DAYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ContactList::create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List created.')]);

        return to_route('marketing.lists.index');
    }

    public function update(Request $request, ContactList $list): RedirectResponse
    {
        $list->update($this->validated($request, $list));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List saved.')]);

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
        ], [
            'name.unique' => __('There is already a list with this name.'),
        ]);
    }
}
