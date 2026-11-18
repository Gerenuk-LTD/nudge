<?php

namespace Gerenuk\Marketing\Actions\Imports;

use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Imports\CsvReader;
use Gerenuk\Marketing\Models\Import;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateImport
{
    /**
     * Store an uploaded CSV and record its headers. The import waits for column mapping before it runs.
     */
    public function handle(Workspace $workspace, MarketingUser $user, UploadedFile $file): Import
    {
        $disk = config('marketing.imports.disk');
        $path = $file->store("imports/{$workspace->id}", $disk)
            ?: throw new RuntimeException('The import file could not be stored.');

        $reader = new CsvReader($disk, $path);
        $headers = $reader->headers();

        if ($headers === [] || collect($headers)->filter()->isEmpty()) {
            Storage::disk($disk)->delete($path);

            throw ValidationException::withMessages(['file' => __('The file has no header row.')]);
        }

        return Import::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->getAuthIdentifier(),
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'headers' => $headers,
            'status' => ImportStatus::Pending,
            'total_rows' => $reader->countRows(),
        ]);
    }
}
