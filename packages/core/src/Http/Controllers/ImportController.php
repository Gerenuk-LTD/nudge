<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Imports\CreateImport;
use Gerenuk\Marketing\Actions\Imports\StartImport;
use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Imports\ColumnMapping;
use Gerenuk\Marketing\Imports\CsvReader;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Import;
use Gerenuk\Marketing\Models\ImportError;
use Gerenuk\Marketing\Models\Tag;
use Gerenuk\Marketing\Support\CsvCell;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    /** Failed rows shown on an import's page; the rest are in the download. */
    private const ERRORS_SHOWN = 10;

    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(): Response
    {
        return Inertia::render('marketing/imports/index', [
            'imports' => Import::query()->latest('id')->limit(100)->get()->map(fn (Import $import) => $this->present($import)),
        ]);
    }

    public function create(Request $request): Response
    {
        $customFields = CustomFieldDefinition::query()->orderBy('label')->get();

        return Inertia::render('marketing/imports/create', [
            'maxFileKilobytes' => config('marketing.imports.max_file_kilobytes'),
            'columns' => collect(ColumnMapping::targets($customFields))
                ->map(fn (string $target) => str_starts_with($target, 'custom:') ? substr($target, 7) : $target)
                ->values(),
            'list' => ContactList::query()->whereKey($request->integer('list'))->first(['id', 'name']),
        ]);
    }

    public function store(Request $request, CreateImport $createImport): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', 'max:'.config('marketing.imports.max_file_kilobytes')],
            'list' => ['nullable', 'integer', Rule::exists('contact_lists', 'id')->where('workspace_id', $this->currentWorkspace->id())],
        ]);

        $import = $createImport->handle($this->currentWorkspace->getOrFail(), $this->user($request), $request->file('file'));

        // An import started from a list carries it through, so the mapping step has that list chosen.
        return to_route('marketing.imports.show', array_filter(['import' => $import, 'list' => $validated['list'] ?? null]));
    }

    public function show(Request $request, Import $import): Response
    {
        $customFields = CustomFieldDefinition::query()->orderBy('label')->get();
        $sample = [];
        $fileMissing = false;

        if ($import->status === ImportStatus::Pending) {
            try {
                foreach ((new CsvReader(config('marketing.imports.disk'), $import->file_path))->rows() as $row) {
                    $sample[] = $row;

                    if (count($sample) === 5) {
                        break;
                    }
                }
            } catch (RuntimeException) {
                // The upload was cleaned up or its disk changed; the page offers to upload it again.
                $fileMissing = true;
            }
        }

        return Inertia::render('marketing/imports/show', [
            'import' => $this->present($import),
            'headers' => $import->headers,
            'sample' => $sample,
            'suggestedMapping' => ColumnMapping::suggest($import->headers, $customFields),
            'targets' => collect(ColumnMapping::targets($customFields))->map(fn (string $target) => [
                'value' => $target,
                'label' => str_starts_with($target, 'custom:')
                    ? $customFields->firstWhere('key', substr($target, 7))?->label
                    : str($target)->headline()->toString(),
            ]),
            'lists' => ContactList::query()->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->pluck('name'),
            'defaultList' => ContactList::query()->whereKey($request->integer('list'))->value('id'),
            'fileMissing' => $fileMissing,
            'destination' => [
                'list' => $import->contact_list_id === null ? null : ContactList::query()->find($import->contact_list_id, ['id', 'name']),
                'tags' => $import->tags ?? [],
            ],
            'failedRows' => $import->errors()->orderBy('row_number')->limit(self::ERRORS_SHOWN)->get(['id', 'row_number', 'email', 'message']),
        ]);
    }

    public function start(Request $request, Import $import, StartImport $startImport): RedirectResponse
    {
        abort_unless($import->status === ImportStatus::Pending, 409, __('This import has already started.'));

        $customFields = CustomFieldDefinition::query()->get();

        $validated = $request->validate([
            'mapping' => ['required', 'array', 'size:'.count($import->headers)],
            'mapping.*' => ['nullable', 'string', Rule::in(ColumnMapping::targets($customFields))],
            'contact_list_id' => ['nullable', 'integer', Rule::exists('contact_lists', 'id')->where('workspace_id', $this->currentWorkspace->id())],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:100'],
        ]);

        $targets = array_filter($validated['mapping']);

        if (! in_array('email', $targets, true)) {
            throw ValidationException::withMessages(['mapping' => __('Choose which column holds the email address.')]);
        }

        if (count($targets) !== count(array_unique($targets))) {
            throw ValidationException::withMessages(['mapping' => __('Each field can only be mapped from one column.')]);
        }

        $startImport->handle($import, array_values($validated['mapping']), $validated['contact_list_id'] ?? null, $validated['tags'] ?? []);

        return to_route('marketing.imports.show', $import);
    }

    /**
     * Remove an import from the history, with its uploaded file. Contacts it brought in are kept.
     */
    public function destroy(Import $import): RedirectResponse
    {
        abort_if(in_array($import->status, [ImportStatus::Queued, ImportStatus::Processing], true), 409, __('This import is still running.'));

        Storage::disk(config('marketing.imports.disk'))->delete($import->file_path);
        $import->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Import removed. Its contacts were kept.')]);

        return to_route('marketing.imports.index');
    }

    /**
     * Download the rows that failed, with the reason for each.
     */
    public function errors(Import $import): StreamedResponse
    {
        $filename = pathinfo($import->original_filename, PATHINFO_FILENAME).'-errors.csv';

        return response()->streamDownload(function () use ($import): void {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Unable to open the output stream.');
            fputcsv($out, ['row', 'email', 'error'], escape: '');

            $import->errors()->lazyById(1000)->each(function (ImportError $error) use ($out): void {
                fputcsv($out, array_map(CsvCell::safe(...), [$error->row_number, $error->email, $error->message]), escape: '');
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Import $import): array
    {
        return [
            'id' => $import->id,
            'original_filename' => $import->original_filename,
            'status' => $import->status->value,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'created_count' => $import->created_count,
            'updated_count' => $import->updated_count,
            'failed_count' => $import->failed_count,
            'created_at' => $import->created_at->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
        ];
    }
}
