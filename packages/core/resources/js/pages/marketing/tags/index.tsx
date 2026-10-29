import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Combine,
    Ellipsis,
    Filter,
    Pencil,
    Plus,
    Tag as TagIcon,
    Trash2,
    Users,
    Workflow,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import TagController from '@/actions/Gerenuk/Marketing/Http/Controllers/TagController';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { edit as editAutomation } from '@/routes/marketing/automations';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import { edit as editSegment } from '@/routes/marketing/segments';
import { index as tagsIndex } from '@/routes/marketing/tags';
import { byId, DataTable } from '@marketing/components/data-table';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';

type Usage = { type: 'segment' | 'automation'; id: number; name: string };

type TagRow = {
    id: number;
    name: string;
    contacts_count: number;
    used_by: Usage[];
};

/** What a dialog is open for, and on which tag. */
type Action =
    | { kind: 'create' }
    | { kind: 'rename' | 'merge' | 'delete'; tag: TagRow };

/** Tags most workspaces use, to create in a click from the empty page. */
const STARTERS = ['customer', 'lead', 'vip', 'beta', 'webinar'];

export default function TagsIndex({
    tags,
    activeContacts,
}: {
    tags: TagRow[];
    activeContacts: number;
}) {
    const canEdit = useCan()('contacts.manage');
    const [action, setAction] = useState<Action | null>(null);
    const [search, setSearch] = useState('');
    const shown = tags.filter((tag) =>
        tag.name.toLowerCase().includes(search.trim().toLowerCase()),
    );

    const columns: ColumnDef<TagRow>[] = [
        {
            accessorKey: 'name',
            header: 'Tag',
            cell: ({ row: { original: tag } }) => (
                <Link
                    href={contactsIndex({ query: { tag: tag.id } })}
                    className="inline-flex max-w-full items-center gap-1.5 rounded-md border bg-muted/40 px-2 py-0.5 text-13 font-medium hover:border-foreground/30"
                >
                    <TagIcon className="size-3 shrink-0 text-muted-foreground" />
                    <span className="truncate">{tag.name}</span>
                </Link>
            ),
        },
        {
            accessorKey: 'contacts_count',
            header: 'Contacts',
            cell: ({ row: { original: tag } }) => (
                <ContactShare
                    count={tag.contacts_count}
                    total={activeContacts}
                />
            ),
        },
        {
            id: 'used_by',
            header: 'Used in',
            cell: ({ row: { original: tag } }) => (
                <UsedIn usage={tag.used_by} />
            ),
        },
        ...(canEdit
            ? [
                  {
                      id: 'actions',
                      header: () => <span className="sr-only">Actions</span>,
                      meta: { className: 'w-px' },
                      cell: ({ row: { original: tag } }) => (
                          <TagActions
                              tag={tag}
                              canMerge={tags.length > 1}
                              onAction={setAction}
                          />
                      ),
                  } satisfies ColumnDef<TagRow>,
              ]
            : []),
    ];

    return (
        <>
            <Head title="Tags" />

            <PageBody>
                <PageHeader
                    title="Tags"
                    description="Labels for contacts, to pick out in segments and automations."
                    actions={
                        canEdit &&
                        tags.length > 0 && (
                            <Button
                                onClick={() => setAction({ kind: 'create' })}
                            >
                                <Plus />
                                New tag
                            </Button>
                        )
                    }
                />

                {tags.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-16 text-center">
                        <span className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
                            <TagIcon className="size-5" />
                        </span>
                        <div className="space-y-1">
                            <p className="font-medium">No tags yet</p>
                            <p className="max-w-md text-sm text-muted-foreground">
                                Tag contacts to group them loosely, like
                                customers or leads. Segments can pick them out,
                                and automations can start when one is added.
                            </p>
                        </div>
                        {canEdit && (
                            <>
                                <Button
                                    size="sm"
                                    className="mt-1"
                                    onClick={() =>
                                        setAction({ kind: 'create' })
                                    }
                                >
                                    <Plus />
                                    New tag
                                </Button>
                                <div className="mt-4 space-y-2.5">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Or add some common ones
                                    </p>
                                    <div className="flex flex-wrap justify-center gap-1.5">
                                        {STARTERS.map((name) => (
                                            <button
                                                key={name}
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        TagController.store.url(),
                                                        { name },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                                className="inline-flex h-8 items-center gap-1.5 rounded-full border bg-card px-3 text-13 text-muted-foreground shadow-xs transition-colors outline-none hover:border-foreground/30 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            >
                                                <Plus className="size-3.5" />
                                                {name}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                ) : (
                    <>
                        {tags.length > 8 && (
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Search tags"
                                aria-label="Search tags"
                                className="h-8 max-w-xs"
                            />
                        )}
                        <DataTable
                            columns={columns}
                            data={shown}
                            getRowId={byId}
                            sortable
                            emptyMessage="No tags match your search."
                        />
                    </>
                )}
            </PageBody>

            {canEdit && (
                <TagDialog
                    action={action}
                    tags={tags}
                    onClose={() => setAction(null)}
                />
            )}
        </>
    );
}

function ContactShare({ count, total }: { count: number; total: number }) {
    const share = total > 0 ? count / total : 0;

    return (
        <div className="flex items-center gap-3">
            <span className="w-10 text-right tabular-nums">
                {count.toLocaleString()}
            </span>
            <span
                className="hidden h-1.5 w-24 overflow-hidden rounded-full bg-muted sm:block"
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

function TagActions({
    tag,
    canMerge,
    onAction,
}: {
    tag: TagRow;
    canMerge: boolean;
    onAction: (action: Action) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground"
                    aria-label={`Actions for ${tag.name}`}
                >
                    <Ellipsis />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    onSelect={() => onAction({ kind: 'rename', tag })}
                >
                    <Pencil />
                    Rename
                </DropdownMenuItem>
                {canMerge && (
                    <DropdownMenuItem
                        onSelect={() => onAction({ kind: 'merge', tag })}
                    >
                        <Combine />
                        Merge into another tag
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem asChild>
                    <Link href={contactsIndex({ query: { tag: tag.id } })}>
                        <Users />
                        View contacts
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    onSelect={() => onAction({ kind: 'delete', tag })}
                >
                    <Trash2 />
                    Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function TagDialog({
    action,
    tags,
    onClose,
}: {
    action: Action | null;
    tags: TagRow[];
    onClose: () => void;
}) {
    const key = action
        ? `${action.kind}-${'tag' in action ? action.tag.id : 'new'}`
        : 'closed';

    return (
        <Dialog
            open={action !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                {action?.kind === 'create' && (
                    <NameForm key={key} tag={null} onClose={onClose} />
                )}
                {action?.kind === 'rename' && (
                    <NameForm key={key} tag={action.tag} onClose={onClose} />
                )}
                {action?.kind === 'merge' && (
                    <MergeForm
                        key={key}
                        tag={action.tag}
                        tags={tags}
                        onClose={onClose}
                    />
                )}
                {action?.kind === 'delete' && (
                    <DeleteConfirm tag={action.tag} onClose={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function NameForm({
    tag,
    onClose,
}: {
    tag: TagRow | null;
    onClose: () => void;
}) {
    const form = useForm({ name: tag?.name ?? '' });
    const automations =
        tag?.used_by.filter((item) => item.type === 'automation').length ?? 0;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            tag ? TagController.update(tag.id) : TagController.store(),
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="space-y-1.5">
                <DialogTitle>
                    {tag ? `Rename ${tag.name}` : 'New tag'}
                </DialogTitle>
                <DialogDescription>
                    {tag
                        ? automations > 0
                            ? 'Contacts keep the tag, and automations that add or remove it are updated to the new name.'
                            : 'Contacts keep the tag under its new name.'
                        : 'Add it to contacts from their profile, an import or the API.'}
                </DialogDescription>
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="tag-name">Name</Label>
                <Input
                    id="tag-name"
                    required
                    autoFocus
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
                <InputError message={form.errors.name} />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {tag ? 'Rename tag' : 'Create tag'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function MergeForm({
    tag,
    tags,
    onClose,
}: {
    tag: TagRow;
    tags: TagRow[];
    onClose: () => void;
}) {
    const others = tags.filter((other) => other.id !== tag.id);
    const form = useForm({ into: String(others[0]?.id ?? '') });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ into: Number(data.into) }));
        form.submit(TagController.merge(tag.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="space-y-1.5">
                <DialogTitle>Merge {tag.name}</DialogTitle>
                <DialogDescription>
                    {tag.contacts_count === 0
                        ? 'It has no contacts yet.'
                        : `Its ${tag.contacts_count === 1 ? 'contact moves' : `${tag.contacts_count.toLocaleString()} contacts move`} to the tag you pick.`}{' '}
                    Segments and automations that use it switch over too, then{' '}
                    {tag.name} is deleted.
                </DialogDescription>
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="merge-into">Merge into</Label>
                <NativeSelect
                    id="merge-into"
                    value={form.data.into}
                    onChange={(event) =>
                        form.setData('into', event.target.value)
                    }
                >
                    {others.map((other) => (
                        <option key={other.id} value={other.id}>
                            {other.name}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={form.errors.into} />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Merge tags
                </Button>
            </DialogFooter>
        </form>
    );
}

function DeleteConfirm({ tag, onClose }: { tag: TagRow; onClose: () => void }) {
    return (
        <>
            <DialogTitle>Delete {tag.name}?</DialogTitle>
            <DialogDescription>
                It is removed from{' '}
                {tag.contacts_count === 1
                    ? '1 contact'
                    : `${tag.contacts_count.toLocaleString()} contacts`}
                . The contacts themselves are kept.
            </DialogDescription>
            {tag.used_by.length > 0 && (
                <div className="rounded-lg border border-amber-300/60 bg-amber-50 px-3.5 py-3 text-sm text-amber-900 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200">
                    <p className="font-medium">Still in use</p>
                    <p className="mt-0.5 text-13">
                        These stop matching anyone on this tag:{' '}
                        {tag.used_by.map((item) => item.name).join(', ')}.
                        Merging it into another tag keeps them working.
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
                        router.delete(TagController.destroy.url(tag.id), {
                            preserveScroll: true,
                            onSuccess: onClose,
                        })
                    }
                >
                    Delete tag
                </Button>
            </DialogFooter>
        </>
    );
}

TagsIndex.layout = {
    breadcrumbs: [{ title: 'Tags', href: tagsIndex() }],
};
