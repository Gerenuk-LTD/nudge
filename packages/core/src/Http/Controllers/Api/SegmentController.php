<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Http\Resources\ContactResource;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SegmentController extends Controller
{
    public function __construct(private readonly SegmentQuery $segmentQuery) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Segment::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Segment $segment): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $segment->id,
            'name' => $segment->name,
            'definition' => $segment->definition,
            'contacts_count' => $this->segmentQuery->contacts($segment->definition)->count(),
        ]]);
    }

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
