<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Http\Resources\ContactResource;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Segments')]
class SegmentController extends Controller
{
    public function __construct(private readonly SegmentQuery $segmentQuery) {}

    /**
     * List segments
     *
     * Every segment in the workspace, by name.
     */
    #[Response(200, 'Segments, ordered by name.', type: 'array{data: list<array{id: int, name: string}>}')]
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Segment::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Get a segment
     *
     * The segment's rule definition and the number of contacts that currently match it.
     */
    #[PathParameter('segment', 'The ID of the segment.', type: 'integer')]
    #[Response(200, 'The segment.', type: 'array{data: array{id: int, name: string, definition: array<string, mixed>, contacts_count: int}}')]
    #[Response(404, 'No segment with that ID.', type: 'array{message: string}')]
    public function show(Segment $segment): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $segment->id,
            'name' => $segment->name,
            'definition' => $segment->definition,
            'contacts_count' => $this->segmentQuery->contacts($segment->definition)->count(),
        ]]);
    }

    /**
     * List the contacts in a segment
     *
     * Contacts that currently match the segment, in ID order, 100 per page by default. Follow `meta.next_cursor` (pass it as `cursor`) to read the next page.
     */
    #[PathParameter('segment', 'The ID of the segment.', type: 'integer')]
    #[QueryParameter('per_page', 'Contacts per page, from 1 to 500. Defaults to 100.', type: 'integer', default: 100)]
    #[QueryParameter('cursor', 'The `meta.next_cursor` value from the previous page.', type: 'string')]
    #[Response(404, 'No segment with that ID.', type: 'array{message: string}')]
    public function contacts(Request $request, Segment $segment): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:500']])['per_page'] ?? 100;

        return ContactResource::collection(
            $this->segmentQuery->contacts($segment->definition)
                ->with(['tags:id,name', 'lists:id'])
                ->orderBy('id')
                ->cursorPaginate($perPage)
                ->withQueryString(),
        );
    }
}
