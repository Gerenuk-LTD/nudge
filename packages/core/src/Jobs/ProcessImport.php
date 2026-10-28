<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Imports\ColumnMapping;
use Gerenuk\Marketing\Imports\CsvReader;
use Gerenuk\Marketing\Models\Import;
use Gerenuk\Marketing\Models\ImportError;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Support\ContactRules;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        $this->onQueue(config('marketing.imports.queue'));
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
        $rules = $contactRules->rules();
        $customFields = $contactRules->definitions()->keyBy('key');
        $tagIds = UpsertContact::resolveTagIds($import->tags ?? []);
        $chunkSize = (int) config('marketing.imports.chunk_size');

        $reader = new CsvReader(config('marketing.imports.disk'), $import->file_path);
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

                ['contact' => $contact, 'created' => $created] = $upsert->handle($data, ContactSource::Import);
                $created ? $counts['created_count']++ : $counts['updated_count']++;

                if ($tagIds !== []) {
                    $contact->tags()->syncWithoutDetaching($tagIds);
                }
            }

            if ($counts['processed_rows'] % $chunkSize === 0) {
                $this->saveProgress($import, $counts, $errors);
                $errors = [];
            }
        }

        $this->saveProgress($import, $counts, $errors);

        $import->update(['status' => ImportStatus::Completed, 'finished_at' => now()]);
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
