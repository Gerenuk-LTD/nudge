<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Models\Tag;
use Illuminate\Http\JsonResponse;

#[Group('Tags')]
class TagController extends Controller
{
    /**
     * List tags
     *
     * Every tag in the workspace, by name, with the number of contacts carrying each. Tags are created by sending their names when upserting a contact.
     */
    #[Response(200, 'Tags, ordered by name.', type: 'array{data: list<array{id: int, name: string, contacts_count: int}>}')]
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Tag::query()->withCount('contacts')->orderBy('name')->get()
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'contacts_count' => $tag->contacts_count]),
        ]);
    }
}
