<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Models\Tag;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Tag::query()->withCount('contacts')->orderBy('name')->get()
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'contacts_count' => $tag->contacts_count]),
        ]);
    }
}
