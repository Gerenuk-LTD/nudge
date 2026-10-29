import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Calendar,
    Check,
    Copy,
    Ellipsis,
    Filter,
    Hash,
    Pencil,
    Plus,
    TextCursorInput,
    ToggleRight,
    Trash2,
    Type,
    Workflow,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CustomFieldController from '@/actions/Gerenuk/Marketing/Http/Controllers/CustomFieldController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import { edit as editAutomation } from '@/routes/marketing/automations';
import { index as customFieldsIndex } from '@/routes/marketing/custom-fields';
import { edit as editSegment } from '@/routes/marketing/segments';
import { byId, DataTable } from '@marketing/components/data-table';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';
import type { CustomFieldType } from '@marketing/types';

type Usage = { type: 'segment' | 'automation'; id: number; name: string };

type FieldRow = {
    id: number;
    key: string;
    label: string;
    type: CustomFieldType;
    filled_count: number;
    used_by: Usage[];
};

type NewField = { label: string; key: string; type: CustomFieldType };

const fieldTypes: Record<
    CustomFieldType,
    { label: string; example: string; icon: LucideIcon }
> = {
    text: { label: 'Text', example: 'Company, plan', icon: Type },
    number: { label: 'Number', example: 'Seats, lifetime value', icon: Hash },
    date: { label: 'Date', example: 'Birthday, renewal', icon: Calendar },
    boolean: {
        label: 'Yes / no',
        example: 'VIP, trial user',
        icon: ToggleRight,
    },
};

/** Fields most workspaces add, to start from on the empty page. */
const STARTERS: NewField[] = [
    { label: 'Company', key: 'company', type: 'text' },
    { label: 'Plan', key: 'plan', type: 'text' },
    { label: 'Birthday', key: 'birthday', type: 'date' },
    { label: 'Lifetime value', key: 'lifetime_value', type: 'number' },
    { label: 'VIP', key: 'vip', type: 'boolean' },
];

/** A key from a label, such as "Company size" to "company_size", in the form keys must take. */
function keyFrom(label: string): string {
    return label
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^[^a-z]+|_+$/g, '')
        .slice(0, 64);
}

const mergeTag = (key: string) => `{{ custom.${key} }}`;

type Action =
    | { kind: 'create'; initial: NewField }
    | { kind: 'rename' | 'delete'; field: FieldRow };

export default function CustomFieldsIndex({
    fields,
    types,
    activeContacts,
}: {
    fields: FieldRow[];
    types: CustomFieldType[];
    activeContacts: number;
}) {
    const canEdit = useCan()('contacts.manage');
    const [action, setAction] = useState<Action | null>(null);
    const [copied, copy] = useClipboard();

    const create = (initial: NewField = { label: '', key: '', type: 'text' }) =>
        setAction({ kind: 'create', initial });

    const columns: ColumnDef<FieldRow>[] = [
        {
            accessorKey: 'label',
            header: 'Field',
            cell: ({ row: { original: field } }) => {
                const Icon = fieldTypes[field.type].icon;

                return (
                    <div className="flex items-center gap-3">
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/40 text-muted-foreground">
                            <Icon className="size-4" />
                        </span>
                        <span className="grid leading-tight">
                            <span className="font-medium">{field.label}</span>
                            <span className="text-xs text-muted-foreground">
                                {fieldTypes[field.type].label}
                            </span>
                        </span>
                    </div>
                );
            },
        },
        {
            id: 'merge_tag',
            header: 'In emails',
            cell: ({ row: { original: field } }) => (
                <button
                    type="button"
                    onClick={() => copy(mergeTag(field.key))}
                    title="Copy to paste into an email"
                    className="group inline-flex items-center gap-1.5 rounded-md border bg-muted/40 px-2 py-0.5 font-mono text-xs hover:border-foreground/30"
                >
                    {mergeTag(field.key)}
                    {copied === mergeTag(field.key) ? (
                        <Check className="size-3 text-emerald-600" />
                    ) : (
                        <Copy className="size-3 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                    )}
                </button>
            ),
        },
        {
            accessorKey: 'filled_count',
            header: 'Filled in',
            cell: ({ row: { original: field } }) => (
                <Filled count={field.filled_count} total={activeContacts} />
            ),
        },
        {
            id: 'used_by',
            header: 'Used in',
            cell: ({ row: { original: field } }) => (
                <UsedIn usage={field.used_by} />
            ),
        },
        ...(canEdit
            ? [
                  {
                      id: 'actions',
                      header: () => <span className="sr-only">Actions</span>,
                      meta: { className: 'w-px' },
                      cell: ({ row: { original: field } }) => (
                          <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                  <Button
                                      variant="ghost"
                                      size="icon"
                                      className="size-8 text-muted-foreground"
                                      aria-label={`Actions for ${field.label}`}
                                  >
                                      <Ellipsis />
                                  </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                  <DropdownMenuItem
                                      onSelect={() =>
                                          setAction({ kind: 'rename', field })
                                      }
                                  >
                                      <Pencil />
                                      Rename
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                      onSelect={() => copy(mergeTag(field.key))}
                                  >
                                      <Copy />
                                      Copy merge tag
                                  </DropdownMenuItem>
                                  <DropdownMenuSeparator />
                                  <DropdownMenuItem
                                      variant="destructive"
                                      onSelect={() =>
                                          setAction({ kind: 'delete', field })
                                      }
                                  >
                                      <Trash2 />
                                      Delete
                                  </DropdownMenuItem>
                              </DropdownMenuContent>
                          </DropdownMenu>
                      ),
                  } satisfies ColumnDef<FieldRow>,
              ]
            : []),
    ];

    return (
        <>
            <Head title="Custom fields" />

            <PageBody>
                <PageHeader
                    title="Custom fields"
                    description="Extra details on each contact, for segments and personalising emails."
                    actions={
                        canEdit &&
                        fields.length > 0 && (
                            <Button onClick={() => create()}>
                                <Plus />
                                New field
                            </Button>
                        )
                    }
                />

                {fields.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-16 text-center">
                        <span className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
                            <TextCursorInput className="size-5" />
                        </span>
                        <div className="space-y-1">
                            <p className="font-medium">No custom fields yet</p>
                            <p className="max-w-md text-sm text-muted-foreground">
                                Store details such as company, plan or birthday
                                on each contact. Fill them from imports or the
                                API, filter on them in segments, and drop them
                                into emails.
                            </p>
                        </div>
                        {canEdit && (
                            <>
                                <Button
                                    size="sm"
                                    className="mt-1"
                                    onClick={() => create()}
                                >
                                    <Plus />
                                    New field
                                </Button>
                                <div className="mt-5 w-full max-w-3xl space-y-2.5">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Or start from one of these
                                    </p>
                                    <div className="grid gap-2 text-left sm:grid-cols-2 lg:grid-cols-3">
                                        {STARTERS.map((starter) => {
                                            const Icon =
                                                fieldTypes[starter.type].icon;

                                            return (
                                                <button
                                                    key={starter.key}
                                                    type="button"
                                                    onClick={() =>
                                                        create(starter)
                                                    }
                                                    className="group flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 text-left shadow-xs transition-colors outline-none hover:border-foreground/20 focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                >
                                                    <Icon className="size-4 shrink-0 text-muted-foreground" />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-sm font-medium">
                                                            {starter.label}
                                                        </span>
                                                        <span className="block truncate text-xs text-muted-foreground">
                                                            {
                                                                fieldTypes[
                                                                    starter.type
                                                                ].label
                                                            }
                                                        </span>
                                                    </span>
                                                    <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                ) : (
                    <DataTable
                        columns={columns}
                        data={fields}
                        getRowId={byId}
                        sortable
                        emptyMessage="No custom fields yet."
                    />
                )}
            </PageBody>

            {canEdit && (
                <Dialog
                    open={action !== null}
                    onOpenChange={(open) => !open && setAction(null)}
                >
                    <DialogContent
                        className={cn(
                            action?.kind === 'create' && 'sm:max-w-xl',
                        )}
                    >
                        {action?.kind === 'create' && (
                            <CreateForm
                                key={action.initial.key || 'blank'}
                                initial={action.initial}
                                types={types}
                                onClose={() => setAction(null)}
                            />
                        )}
                        {action?.kind === 'rename' && (
                            <RenameForm
                                key={action.field.id}
                                field={action.field}
                                onClose={() => setAction(null)}
                            />
                        )}
                        {action?.kind === 'delete' && (
                            <DeleteConfirm
                                field={action.field}
                                onClose={() => setAction(null)}
                            />
                        )}
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

function Filled({ count, total }: { count: number; total: number }) {
    const share = total > 0 ? count / total : 0;

    return (
        <div className="flex items-center gap-3">
            <span className="w-10 text-right tabular-nums">
                {count.toLocaleString()}
            </span>
            <span
                className="hidden h-1.5 w-20 overflow-hidden rounded-full bg-muted sm:block"
                role="presentation"
            >
                <span
                    className="block h-full rounded-full bg-foreground/60"
                    style={{
                        width: `${Math.max(share * 100, count > 0 ? 3 : 0)}%`,
                    }}
                />
            </span>
            <span className="hidden text-xs text-muted-foreground tabular-nums sm:inline">
                {Math.round(share * 100)}%
            </span>
        </div>
    );
}

function UsedIn({ usage }: { usage: Usage[] }) {
    if (usage.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {usage.map((item) => {
                const Icon = item.type === 'segment' ? Filter : Workflow;

                return (
                    <Link
                        key={`${item.type}-${item.id}`}
                        href={
                            item.type === 'segment'
                                ? editSegment(item.id)
                                : editAutomation(item.id)
                        }
                        title={`${item.type === 'segment' ? 'Segment' : 'Automation'}: ${item.name}`}
                        className="inline-flex max-w-48 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <Icon className="size-3 shrink-0" />
                        <span className="truncate">{item.name}</span>
                    </Link>
                );
            })}
        </div>
    );
}

function CreateForm({
    initial,
    types,
    onClose,
}: {
    initial: NewField;
    types: CustomFieldType[];
    onClose: () => void;
}) {
    const form = useForm<NewField>(initial);
    // The key follows the label until it is edited by hand.
    const [keyEdited, setKeyEdited] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(CustomFieldController.store(), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-5">
            <div className="space-y-1.5">
                <DialogTitle>New field</DialogTitle>
                <DialogDescription>
                    Every contact gets this field, empty until you fill it in by
                    hand, from an import or through the API.
                </DialogDescription>
            </div>

            <div className="grid content-start gap-2">
                <Label htmlFor="field-label">Label</Label>
                <Input
                    id="field-label"
                    required
                    autoFocus
                    placeholder="Company size"
                    value={form.data.label}
                    onChange={(event) => {
                        form.setData((data) => ({
                            ...data,
                            label: event.target.value,
                            key: keyEdited
                                ? data.key
                                : keyFrom(event.target.value),
                        }));
                    }}
                />
                <InputError message={form.errors.label} />
            </div>

            <div className="grid content-start gap-2">
                <Label>Type</Label>
                <div
                    role="radiogroup"
                    aria-label="Type"
                    className="grid grid-cols-2 gap-2"
                >
                    {types.map((type) => {
                        const { label, example, icon: Icon } = fieldTypes[type];
                        const selected = form.data.type === type;

                        return (
                            <button
                                key={type}
                                type="button"
                                role="radio"
                                aria-checked={selected}
                                onClick={() => form.setData('type', type)}
                                className={cn(
                                    'flex items-center gap-3 rounded-lg border bg-card px-3 py-2.5 text-left transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    selected
                                        ? 'border-foreground ring-1 ring-foreground'
                                        : 'hover:border-foreground/30',
                                )}
                            >
                                <Icon className="size-4 shrink-0 text-muted-foreground" />
                                <span className="min-w-0">
                                    <span className="block text-sm font-medium">
                                        {label}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {example}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>
                <InputError message={form.errors.type} />
            </div>

            <div className="grid content-start gap-2">
                <Label htmlFor="field-key">Key</Label>
                <Input
                    id="field-key"
                    required
                    placeholder="company_size"
                    className="font-mono text-13"
                    value={form.data.key}
                    onChange={(event) => {
                        setKeyEdited(true);
                        form.setData('key', event.target.value);
                    }}
                />
                <p className="text-xs text-muted-foreground">
                    {form.data.key ? (
                        <>
                            In emails as{' '}
                            <code className="font-mono">
                                {mergeTag(form.data.key)}
                            </code>
                            . The API and imports use the key too, so it cannot
                            change later.
                        </>
                    ) : (
                        'Used in emails, the API and imports. It cannot change later.'
                    )}
                </p>
                <InputError message={form.errors.key} />
            </div>

            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Add field
                </Button>
            </DialogFooter>
        </form>
    );
}

function RenameForm({
    field,
    onClose,
}: {
    field: FieldRow;
    onClose: () => void;
}) {
    const form = useForm({ label: field.label });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(CustomFieldController.update(field.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="space-y-1.5">
                <DialogTitle>Rename {field.label}</DialogTitle>
                <DialogDescription>
                    Only the label changes. The key{' '}
                    <code className="font-mono text-xs">{field.key}</code> and
                    the values on contacts stay the same.
                </DialogDescription>
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="rename-label">Label</Label>
                <Input
                    id="rename-label"
                    required
                    autoFocus
                    value={form.data.label}
                    onChange={(event) =>
                        form.setData('label', event.target.value)
                    }
                />
                <InputError message={form.errors.label} />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Rename field
                </Button>
            </DialogFooter>
        </form>
    );
}

function DeleteConfirm({
    field,
    onClose,
}: {
    field: FieldRow;
    onClose: () => void;
}) {
    return (
        <>
            <DialogTitle>Delete {field.label}?</DialogTitle>
            <DialogDescription>
                It disappears from contact pages, segments and the API.
                {field.filled_count > 0 &&
                    ` The ${field.filled_count.toLocaleString()} values already stored stay on contacts, and come back if you add a field with the key ${field.key} again.`}
            </DialogDescription>
            {field.used_by.length > 0 && (
                <div className="rounded-lg border border-amber-300/60 bg-amber-50 px-3.5 py-3 text-sm text-amber-900 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200">
                    <p className="font-medium">Still in use</p>
                    <p className="mt-0.5 text-13">
                        These rely on it and will stop working as expected:{' '}
                        {field.used_by.map((item) => item.name).join(', ')}.
                    </p>
                </div>
            )}
            <DialogFooter>
                <DialogClose asChild>
                    <Button variant="ghost">Cancel</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    onClick={() =>
                        router.delete(
                            CustomFieldController.destroy.url(field.id),
                            { preserveScroll: true, onSuccess: onClose },
                        )
                    }
                >
                    Delete field
                </Button>
            </DialogFooter>
        </>
    );
}

CustomFieldsIndex.layout = {
    breadcrumbs: [{ title: 'Custom fields', href: customFieldsIndex() }],
};
