<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Segments\SegmentDefinitionValidator;
use Gerenuk\Marketing\Segments\SegmentField;
use Gerenuk\Marketing\Segments\SegmentFieldCatalog;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SegmentController extends Controller
{
    public function __construct(
        private readonly SegmentQuery $segmentQuery,
        private readonly SegmentFieldCatalog $catalog,
        private readonly SegmentDefinitionValidator $validator,
    ) {}

    public function index(): Response
    {
        return Inertia::render('marketing/segments/index', [
            'segments' => Segment::query()->orderBy('name')->get()->map(fn (Segment $segment) => [
                'id' => $segment->id,
                'name' => $segment->name,
                'contacts_count' => $this->segmentQuery->contacts($segment->definition)->count(),
                'updated_at' => $segment->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(Request $request, CurrentWorkspace $currentWorkspace): RedirectResponse
    {
        $segment = Segment::create($this->validated($request, $currentWorkspace));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Segment saved.')]);

        return to_route('marketing.segments.edit', $segment);
    }

    public function edit(Segment $segment): Response
    {
        return $this->form($segment);
    }

    public function update(Request $request, Segment $segment, CurrentWorkspace $currentWorkspace): RedirectResponse
    {
        $segment->update($this->validated($request, $currentWorkspace, $segment));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Segment saved.')]);

        return to_route('marketing.segments.edit', $segment);
    }

    public function destroy(Segment $segment): RedirectResponse
    {
        $segment->delete();

        return to_route('marketing.segments.index');
    }

    /**
     * The live count and a sample of matching contacts while a segment is being built.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->validator->validate($request->input('definition'));

        $query = $this->segmentQuery->contacts($request->input('definition'));

        return response()->json([
            'count' => (clone $query)->count(),
            'sample' => $query->orderByDesc('id')->limit(10)->get(['id', 'email', 'first_name', 'last_name'])
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'email' => $contact->email,
                    'name' => trim("{$contact->first_name} {$contact->last_name}") ?: null,
                ]),
        ]);
    }

    private function form(?Segment $segment): Response
    {
        return Inertia::render('marketing/segments/form', [
            'segment' => $segment === null ? null : [
                'id' => $segment->id,
                'name' => $segment->name,
                'definition' => $segment->definition,
            ],
            'fields' => array_values(array_map(fn (SegmentField $field) => $field->toArray(), $this->catalog->all())),
        ]);
    }

    /**
     * @return array{name: string, definition: array<string, mixed>}
     */
    private function validated(Request $request, CurrentWorkspace $currentWorkspace, ?Segment $segment = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('segments', 'name')->where('workspace_id', $currentWorkspace->id())->ignore($segment?->id),
            ],
            'definition' => ['required', 'array'],
        ]);

        $this->validator->validate($validated['definition']);

        return $validated;
    }
}
