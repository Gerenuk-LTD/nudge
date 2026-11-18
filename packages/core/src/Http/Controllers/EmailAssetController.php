<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\EmailAsset;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Images for the email builder: upload one, or pick from the workspace's recent uploads.
 */
class EmailAssetController extends Controller
{
    /** How many recent uploads the image picker offers. */
    private const RECENT = 24;

    public function index(): JsonResponse
    {
        $this->authorizeEditing();

        return response()->json([
            'assets' => EmailAsset::query()->latest('id')->limit(self::RECENT)->get()->map(fn (EmailAsset $asset) => $asset->toPicker()),
        ]);
    }

    public function store(Request $request, CurrentWorkspace $currentWorkspace): JsonResponse
    {
        $this->authorizeEditing();

        // SVG is left out on purpose: it can carry scripts, and many inboxes will not show it anyway.
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.config('marketing.email_assets.max_file_kilobytes')],
        ]);

        $file = $request->file('file');
        $disk = config('marketing.email_assets.disk');
        $workspace = $currentWorkspace->getOrFail();
        $path = $file->store("email-assets/{$workspace->id}", ['disk' => $disk, 'visibility' => 'public'])
            ?: throw new RuntimeException('The image could not be stored.');
        $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];

        $asset = EmailAsset::create([
            'uploaded_by' => $this->user($request)->getAuthIdentifier(),
            'disk' => $disk,
            'path' => $path,
            'name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ]);

        return response()->json(['asset' => $asset->toPicker()], 201);
    }

    /**
     * Anyone who edits emails can add images: campaign editors and automation editors alike.
     */
    private function authorizeEditing(): void
    {
        abort_unless(Gate::any([Permission::CampaignsManage->value, Permission::AutomationsManage->value]), 403);
    }
}
