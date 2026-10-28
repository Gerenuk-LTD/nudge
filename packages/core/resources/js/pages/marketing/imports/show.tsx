import { Head, useForm, usePoll } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ImportController from '@/actions/Gerenuk/Marketing/Http/Controllers/ImportController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index as importsIndex } from '@/routes/marketing/imports';
import { Cell, DataTable } from '@marketing/components/data-table';
import { ImportStatusBadge } from '@marketing/components/import-status-badge';
import { NativeSelect } from '@marketing/components/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { TagInput } from '@marketing/components/tag-input';
import type { ImportSummary, Option } from '@marketing/types';

type Props = {
    import: ImportSummary;
    headers: string[];
    sample: (string | null)[][];
    suggestedMapping: (string | null)[];
    targets: { value: string; label: string }[];
    lists: Option[];
    tags: string[];
};

export default function ShowImport(props: Props) {
    const { import: item } = props;

    return (
        <>
            <Head title={item.original_filename} />

            <PageBody>
                <PageHeader
                    title={item.original_filename}
                    description={`${item.total_rows.toLocaleString()} rows`}
                    actions={<ImportStatusBadge status={item.status} />}
                />

                {item.status === 'pending' ? (
                    <MappingForm {...props} />
                ) : (
                    <Progress item={item} />
                )}
            </PageBody>
        </>
    );
}

function MappingForm({
    import: item,
    headers,
    sample,
    suggestedMapping,
    targets,
    lists,
    tags,
}: Props) {
    const form = useForm<{
        mapping: (string | null)[];
        contact_list_id: string;
        tags: string[];
    }>({
        mapping: suggestedMapping,
        contact_list_id: '',
        tags: [],
    });

    const setTarget = (index: number, target: string) =>
        form.setData(
            'mapping',
            form.data.mapping.map((existing, i) =>
                i === index ? target || null : existing,
            ),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            contact_list_id:
                data.contact_list_id === ''
                    ? null
                    : Number(data.contact_list_id),
        }));
        form.submit(ImportController.start(item.id));
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="space-y-6">
            <p className="text-sm text-muted-foreground">
                Choose what each column holds. Columns set to "Skip" are
                ignored. Contacts already in the workspace are updated by email
                address. An unsubscribed status is applied, but an import never
                re-subscribes anyone who opted out.
            </p>

            <DataTable headers={['Column', 'Example values', 'Import as']}>
                {headers.map((header, index) => (
                    <tr key={index}>
                        <Cell className="font-medium">
                            {header || `Column ${index + 1}`}
                        </Cell>
                        <Cell className="max-w-xs truncate text-muted-foreground">
                            {sample
                                .map((row) => row[index])
                                .filter(Boolean)
                                .slice(0, 3)
                                .join(', ')}
                        </Cell>
                        <Cell>
                            <NativeSelect
                                aria-label={`Import ${header} as`}
                                value={form.data.mapping[index] ?? ''}
                                onChange={(event) =>
                                    setTarget(index, event.target.value)
                                }
                            >
                                <option value="">Skip</option>
                                {targets.map((target) => (
                                    <option
                                        key={target.value}
                                        value={target.value}
                                    >
                                        {target.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Cell>
                    </tr>
                ))}
            </DataTable>
            <InputError message={errors.mapping} />

            <div className="grid max-w-3xl gap-6 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="contact_list_id">
                        Add everyone to a list
                    </Label>
                    <NativeSelect
                        id="contact_list_id"
                        value={form.data.contact_list_id}
                        onChange={(event) =>
                            form.setData('contact_list_id', event.target.value)
                        }
                    >
                        <option value="">No list</option>
                        {lists.map((list) => (
                            <option key={list.id} value={list.id}>
                                {list.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={errors.contact_list_id} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="import-tags">Tag everyone</Label>
                    <TagInput
                        id="import-tags"
                        value={form.data.tags}
                        onChange={(value) => form.setData('tags', value)}
                        suggestions={tags}
                    />
                    <InputError message={errors.tags} />
                </div>
            </div>

            <Button disabled={form.processing}>
                {form.processing && <Spinner />}
                Start import
            </Button>
        </form>
    );
}

function Progress({ item }: { item: ImportSummary }) {
    const running = ['queued', 'processing'].includes(item.status);
    const percentage =
        item.total_rows > 0
            ? Math.round((item.processed_rows / item.total_rows) * 100)
            : 100;

    usePoll(2000, { only: ['import'] }, { autoStart: running });

    return (
        <div className="max-w-xl space-y-6">
            <div className="space-y-2">
                <div className="flex justify-between text-sm">
                    <span>
                        {item.processed_rows.toLocaleString()} of{' '}
                        {item.total_rows.toLocaleString()} rows
                    </span>
                    <span className="tabular-nums">{percentage}%</span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full bg-primary transition-all"
                        style={{ width: `${percentage}%` }}
                    />
                </div>
            </div>

            <dl className="grid grid-cols-3 gap-4">
                <Stat label="Created" value={item.created_count} />
                <Stat label="Updated" value={item.updated_count} />
                <Stat label="Failed" value={item.failed_count} />
            </dl>

            {item.status === 'failed' && (
                <p className="text-sm text-red-600 dark:text-red-400">
                    The import stopped because of an error. Rows before it were
                    saved.
                </p>
            )}

            {item.failed_count > 0 && (
                <Button variant="outline" asChild>
                    <a href={ImportController.errors.url(item.id)}>
                        Download failed rows
                    </a>
                </Button>
            )}
        </div>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-2xl font-semibold tabular-nums">
                {value.toLocaleString()}
            </dd>
        </div>
    );
}

ShowImport.layout = {
    breadcrumbs: [{ title: 'Imports', href: importsIndex() }],
};
