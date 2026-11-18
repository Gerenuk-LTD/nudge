<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Enums\SuppressionReason;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Support\CsvCell;
use Gerenuk\Marketing\Support\TableQuery;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SuppressionController extends Controller
{
    /** The most addresses one paste can block, to keep a request quick. */
    private const MAX_BLOCKED_AT_ONCE = 1000;

    public function index(Request $request): Response
    {
        $query = Suppression::query()
            ->when(TableQuery::values($request->input('reason')), fn ($query, array $reasons) => $query->whereIn('reason', $reasons));

        TableQuery::search($query, $request->string('search')->toString(), ['email']);

        $suppressions = TableQuery::sort($query, $request, [
            'email' => 'email',
            'reason' => 'reason',
            'since' => 'created_at',
        ])
            ->paginate(TableQuery::perPage($request, 50))
            ->withQueryString();

        $contacts = Contact::query()
            ->whereIn('email', $suppressions->getCollection()->pluck('email'))
            ->get(['id', 'email', 'first_name', 'last_name'])
            ->keyBy('email');

        return Inertia::render('marketing/suppressions/index', [
            'suppressions' => $suppressions->through(fn (Suppression $suppression) => [
                'id' => $suppression->id,
                'email' => $suppression->email,
                'reason' => $suppression->reason->value,
                'detail' => $suppression->detail,
                'created_at' => $suppression->created_at?->toIso8601String(),
                'contact' => ($contact = $contacts->get($suppression->email)) === null ? null : [
                    'id' => $contact->id,
                    'name' => trim("{$contact->first_name} {$contact->last_name}") ?: null,
                ],
            ]),
            'counts' => Suppression::query()->selectRaw('reason, count(*) as total')->groupBy('reason')->pluck('total', 'reason')
                ->map(fn (mixed $total) => (int) $total),
        ]);
    }

    /**
     * Block one or more addresses, pasted one per line or separated by commas. Invalid ones are reported back.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'emails' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $emails = array_values(array_unique(array_filter(
            array_map(fn (string $email) => Contact::normalizeEmail($email), preg_split('/[\s,;]+/', $request->string('emails')->toString()) ?: []),
        )));

        if (count($emails) > self::MAX_BLOCKED_AT_ONCE) {
            throw ValidationException::withMessages(['emails' => __('Block up to :max addresses at a time.', ['max' => self::MAX_BLOCKED_AT_ONCE])]);
        }

        $invalid = array_values(array_filter($emails, fn (string $email) => Validator::make(['email' => $email], ['email' => 'email:filter|max:254'])->fails()));

        if ($emails === [] || $invalid !== []) {
            throw ValidationException::withMessages(['emails' => $emails === []
                ? __('Enter at least one email address.')
                : trans_choice('This is not an email address: :list|These are not email addresses: :list', count($invalid), ['list' => implode(', ', array_slice($invalid, 0, 5))])]);
        }

        foreach ($emails as $email) {
            Suppression::suppress($email, SuppressionReason::Manual, $request->string('note')->toString() ?: __('Added by hand'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Blocked :count address.|Blocked :count addresses.', count($emails))]);

        return to_route('marketing.suppressions.index');
    }

    /**
     * Removing a bounce or complaint suppression lets the address be emailed again, so it is deliberate.
     */
    public function destroy(Request $request, Suppression $suppression, CurrentWorkspace $currentWorkspace): RedirectResponse
    {
        $suppression->delete();

        // A suppression may have no contact, so its removal can't go on a timeline. No email address in the log.
        Log::notice('Suppression removed', [
            'workspace' => $suppression->workspace_id,
            'suppression' => $suppression->id,
            'reason' => $suppression->reason->value,
            'by' => $this->user($request)->getAuthIdentifier(),
        ]);

        $canViewContacts = $this->user($request)->hasWorkspacePermission($currentWorkspace->getOrFail(), Permission::ContactsView);

        Inertia::flash('toast', ['type' => 'success', 'message' => $canViewContacts
            ? __(':email can be emailed again.', ['email' => $suppression->email])
            : __('The address can be emailed again.')]);

        return back();
    }

    /**
     * The whole list as a CSV, for moving to another tool or provider.
     */
    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Unable to open the output stream.');
            fputcsv($out, ['email', 'reason', 'detail', 'since'], escape: '');

            Suppression::query()->lazyById(1000)->each(function (Suppression $suppression) use ($out): void {
                fputcsv($out, array_map(CsvCell::safe(...), [$suppression->email, $suppression->reason->value, $suppression->detail, $suppression->created_at?->toDateString()]), escape: '');
            });

            fclose($out);
        }, 'suppressions.csv', ['Content-Type' => 'text/csv']);
    }
}
