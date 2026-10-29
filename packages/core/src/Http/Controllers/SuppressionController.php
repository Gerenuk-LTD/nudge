<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Enums\SuppressionReason;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuppressionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Suppression::query()
            ->when(TableQuery::values($request->input('reason')), fn ($query, array $reasons) => $query->whereIn('reason', $reasons));

        TableQuery::search($query, $request->string('search')->toString(), ['email']);

        return Inertia::render('marketing/suppressions/index', [
            'suppressions' => TableQuery::sort($query, $request, [
                'email' => 'email',
                'reason' => 'reason',
                'since' => 'created_at',
            ])
                ->paginate(TableQuery::perPage($request, 50))
                ->withQueryString()
                ->through(fn (Suppression $suppression) => [
                    'id' => $suppression->id,
                    'email' => $suppression->email,
                    'reason' => $suppression->reason->value,
                    'detail' => $suppression->detail,
                    'created_at' => $suppression->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email:filter', 'max:254']]);

        Suppression::suppress($validated['email'], SuppressionReason::Manual, __('Added by hand'));

        return to_route('marketing.suppressions.index');
    }

    /**
     * Removing a bounce or complaint suppression lets the address be emailed again, so it is deliberate.
     */
    public function destroy(Suppression $suppression): RedirectResponse
    {
        $suppression->delete();

        return to_route('marketing.suppressions.index');
    }
}
