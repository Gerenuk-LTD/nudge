<?php

namespace Gerenuk\Nudge\Jobs;

use Gerenuk\Nudge\Actions\Contacts\UpsertContact;
use Gerenuk\Nudge\Automations\AutomationTriggers;
use Gerenuk\Nudge\Enums\ContactSource;
use Gerenuk\Nudge\Enums\ImportStatus;
use Gerenuk\Nudge\Imports\ColumnMapping;
use Gerenuk\Nudge\Imports\CsvReader;
use Gerenuk\Nudge\Models\Import;
use Gerenuk\Nudge\Models\ImportError;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Support\ContactData;
use Gerenuk\Nudge\Support\ContactRules;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Processes an import in chunks. Progress is saved after every chunk, so a retried job resumes where it stopped.
 */
class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(public Import $import)
    {
        $this->onQueue(config('nudge.imports.queue'));
    }

    public function handle(CurrentWorkspace $currentWorkspace): void
    {
        $workspace = Workspace::findOrFail($this->import->workspace_id);

        $currentWorkspace->runAs($workspace, fn () => $this->process());
    }

    public function failed(?Throwable $exception): void
    {
        Import::withoutGlobalScopes()->whereKey($this->import->id)->update([
            'status' => ImportStatus::Failed,
            'finished_at' => now(),
        ]);

        self::deleteUpload($this->import);
    }

    /**
     * Delete an import's uploaded spreadsheet. Once processed, it's only a copy of contact data, often with columns
     * that were never mapped, so it isn't kept. A file that's already gone is fine.
     */
    public static function deleteUpload(Import $import): void
    {
        try {
            Storage::disk(config('nudge.imports.disk'))->delete($import->file_path);
        } catch (Throwable $exception) {
            Log::warning('Import upload could not be deleted', ['import' => $import->id, 'exception' => $exception::class]);
        }
    }

    private function process(): void
    {
        $import = $this->import->refresh();

        if ($import->status === ImportStatus::Completed) {
            return;
        }

        $import->update(['status' => ImportStatus::Processing, 'started_at' => $import->started_at ?? now()]);

        $contactRules = app(ContactRules::class);
        $upsert = app(UpsertContact::class);
        $triggers = app(AutomationTriggers::class);
        $rules = $contactRules->rules();
        $customFields = $contactRules->definitions()->keyBy('key');
        $tagIds = UpsertContact::resolveTagIds($import->tags ?? []);
        $chunkSize = (int) config('nudge.imports.chunk_size');

        $reader = new CsvReader(config('nudge.imports.disk'), $import->file_path);
        $counts = ['processed_rows' => 0, 'created_count' => 0, 'updated_count' => 0, 'failed_count' => 0];
        $errors = [];

        foreach ($reader->rows() as $line => $row) {
            $counts['processed_rows']++;

            // Rows handled by an earlier attempt are skipped.
            if ($counts['processed_rows'] <= $import->processed_rows) {
                continue;
            }

            $data = ColumnMapping::toContactData($import->column_mapping, $row, $customFields);
            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                $counts['failed_count']++;
                $errors[] = [
                    'workspace_id' => $import->workspace_id,
                    'import_id' => $import->id,
                    'row_number' => $line,
                    'email' => isset($data['email']) ? mb_substr((string) $data['email'], 0, 255) : null,
                    'message' => implode(' ', $validator->errors()->all()),
                    'created_at' => now(),
                ];
            } else {
                $data = ContactData::fromValidated($validator->validated());

                if ($import->contact_list_id !== null) {
                    $data = $data->withList($import->contact_list_id);
                }

                try {
                    ['contact' => $contact, 'created' => $created] = $upsert->handle($data, ContactSource::Import);
                    $created ? $counts['created_count']++ : $counts['updated_count']++;

                    if ($tagIds !== []) {
                        $triggers->tagsAdded($contact, $contact->tags()->syncWithoutDetaching($tagIds)['attached']);
                    }
                } catch (QueryException $exception) {
                    // Not report(): the exception's SQL carries the row's bindings, which are contact data.
                    Log::warning('Import row could not be saved', [
                        'import' => $import->id,
                        'row' => $line,
                        'sqlstate' => $exception->errorInfo[0] ?? $exception->getCode(),
                    ]);

                    $counts['failed_count']++;
                    $errors[] = [
                        'workspace_id' => $import->workspace_id,
                        'import_id' => $import->id,
                        'row_number' => $line,
                        'email' => mb_substr($data->email, 0, 255),
                        'message' => __('This row could not be saved.'),
                        'created_at' => now(),
                    ];
                }
            }

            if ($counts['processed_rows'] % $chunkSize === 0) {
                $this->saveProgress($import, $counts, $errors);
                $errors = [];
            }
        }

        $this->saveProgress($import, $counts, $errors);

        $import->update(['status' => ImportStatus::Completed, 'finished_at' => now()]);

        self::deleteUpload($import);
    }

    /**
     * @param  array<string, int>  $counts  Totals for this attempt, including rows skipped from earlier attempts.
     * @param  list<array<string, mixed>>  $errors
     */
    private function saveProgress(Import $import, array &$counts, array $errors): void
    {
        if ($counts['processed_rows'] <= $import->processed_rows) {
            return;
        }

        if ($errors !== []) {
            ImportError::insert($errors);
        }

        $import->update([
            'processed_rows' => $counts['processed_rows'],
            'created_count' => $import->created_count + $counts['created_count'],
            'updated_count' => $import->updated_count + $counts['updated_count'],
            'failed_count' => $import->failed_count + $counts['failed_count'],
        ]);

        $counts['created_count'] = $counts['updated_count'] = $counts['failed_count'] = 0;
    }
}
