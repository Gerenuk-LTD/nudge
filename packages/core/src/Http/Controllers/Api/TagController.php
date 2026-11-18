<?php

namespace Gerenuk\Nudge\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Gerenuk\Nudge\Http\Controllers\Controller;
use Gerenuk\Nudge\Http\Resources\TagResource;
use Gerenuk\Nudge\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Tags')]
class TagController extends Controller
{
    /**
     * List tags
     *
     * Every tag in the workspace, by name, with the number of contacts carrying each, 100 per page by default. Tags are created by sending their names when upserting a contact. Follow `meta.next_cursor` (pass it as `cursor`) to read the next page.
     */
    #[QueryParameter('per_page', 'Tags per page, from 1 to 500. Defaults to 100.', type: 'integer', default: 100)]
    #[QueryParameter('cursor', 'The `meta.next_cursor` value from the previous page.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:500']])['per_page'] ?? 100;

        return TagResource::collection(
            Tag::query()->withCount('contacts')->orderBy('name')->orderBy('id')->cursorPaginate($perPage)->withQueryString(),
        );
    }
}
