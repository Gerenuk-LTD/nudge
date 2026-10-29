import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Ellipsis,
    List,
    Pencil,
    Plus,
    Trash2,
    Upload,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ContactListController from '@/actions/Gerenuk/Marketing/Http/Controllers/ContactListController';
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
import { Textarea } from '@/components/ui/textarea';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import { create as createImport } from '@/routes/marketing/imports';
import { index as listsIndex } from '@/routes/marketing/lists';
import type { DailyCount } from '@marketing/components/charts';
import { Sparkline } from '@marketing/components/charts';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';

type ListRow = {
    id: number;
    name: string;
    description: string | null;
    contacts_count: number;
    subscribed_count: number;
    joins: DailyCount[];
};

type ListDetails = { name: string; description: string };

/** Lists most workspaces end up with, to create in a click from the empty page. */
const STARTERS: ListDetails[] = [
    {
        name: 'Newsletter',
        description: 'People who signed up for regular news.',
    },
    { name: 'Customers', description: 'Everyone who has bought from you.' },
    { name: 'Product updates', description: 'Release notes and new features.' },
    {
        name: 'Event attendees',
        description: 'People who came to an event or webinar.',
    },
];

/** What the list dialog is doing: adding a list, maybe from a starter, or editing one. */
type Editing = { list: ListRow | null; initial: ListDetails };

export default function ListsIndex({
    lists,
    days,
}: {
    lists: ListRow[];
    days: number;
}) {
    const can = useCan();
    const canEdit = can('contacts.manage');
    const canImport = can('contacts.import');
    const [editing, setEditing] = useState<Editing | null>(null);

    const add = (initial: ListDetails = { name: '', description: '' }) =>
        setEditing({ list: null, initial });

    return (
        <>
            <Head title="Lists" />

            <PageBody>
                <PageHeader
                    title="Lists"
                    description="Group contacts by what they signed up for. A contact can be on many lists."
                    actions={
                        canEdit &&
                        lists.length > 0 && (
                            <Button onClick={() => add()}>
                                <Plus />
                                New list
                            </Button>
                        )
                    }
                />

                {lists.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-16 text-center">
                        <span className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
                            <List className="size-5" />
                        </span>
                        <div className="space-y-1">
                            <p className="font-medium">No lists yet</p>
                            <p className="max-w-md text-sm text-muted-foreground">
                                Lists are the audiences you send campaigns to,
                                like a newsletter or your customers. Make one,
                                then import or add contacts to it.
                            </p>
                        </div>
                        {canEdit && (
                            <>
                                <Button
                                    size="sm"
                                    className="mt-1"
                                    onClick={() => add()}
                                >
                                    <Plus />
                                    New list
                                </Button>
                                <div className="mt-5 w-full max-w-3xl space-y-2.5">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Or start from one of these
                                    </p>
                                    <div className="grid gap-2 text-left sm:grid-cols-2">
                                        {STARTERS.map((starter) => (
                                            <button
                                                key={starter.name}
                                                type="button"
                                                onClick={() => add(starter)}
                                                className="group flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 text-left shadow-xs transition-colors outline-none hover:border-foreground/20 focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm font-medium">
                                                        {starter.name}
                                                    </span>
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        {starter.description}
                                                    </span>
                                                </span>
                                                <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {lists.map((list) => (
                            <ListCard
                                key={list.id}
                                list={list}
                                days={days}
                                canEdit={canEdit}
                                canImport={canImport}
                                onEdit={() =>
                                    setEditing({
                                        list,
                                        initial: {
                                            name: list.name,
                                            description: list.description ?? '',
                                        },
                                    })
                                }
                            />
                        ))}
                    </div>
                )}
            </PageBody>

            {canEdit && (
                <ListDialog
                    editing={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function ListCard({
    list,
    days,
    canEdit,
    canImport,
    onEdit,
}: {
    list: ListRow;
    days: number;
    canEdit: boolean;
    canImport: boolean;
    onEdit: () => void;
}) {
    const [deleting, setDeleting] = useState(false);
    const joined = list.joins.reduce((sum, day) => sum + day.count, 0);
    const contacts = contactsIndex({ query: { list: list.id } });
    const unsubscribed = list.contacts_count - list.subscribed_count;

    return (
        <div className="group relative flex flex-col rounded-xl border bg-card shadow-xs transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm">
            <div className="flex items-start justify-between gap-3 px-5 pt-4">
                <div className="min-w-0 pt-1">
                    <Link
                        href={contacts}
                        className="block truncate text-sm font-semibold after:absolute after:inset-0 after:rounded-xl"
                    >
                        {list.name}
                    </Link>
                    {list.description && (
                        <p className="mt-0.5 line-clamp-2 text-13 text-muted-foreground">
                            {list.description}
                        </p>
                    )}
                </div>
                {(canEdit || canImport) && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="relative z-10 -mr-2 size-8 shrink-0 text-muted-foreground"
                                aria-label={`Actions for ${list.name}`}
                            >
                                <Ellipsis />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {canEdit && (
                                <DropdownMenuItem onSelect={onEdit}>
                                    <Pencil />
                                    Edit details
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem asChild>
                                <Link href={contacts}>
                                    <Users />
                                    View contacts
                                </Link>
                            </DropdownMenuItem>
                            {canImport && (
                                <DropdownMenuItem asChild>
                                    <Link
                                        href={createImport({
                                            query: { list: list.id },
                                        })}
                                    >
                                        <Upload />
                                        Import into this list
                                    </Link>
                                </DropdownMenuItem>
                            )}
                            {canEdit && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => setDeleting(true)}
                                    >
                                        <Trash2 />
                                        Delete
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            <div className="flex-1 px-5 pt-3 pb-4">
                <p className="text-sm">
                    <span className="text-2xl font-semibold tabular-nums">
                        {list.contacts_count.toLocaleString()}
                    </span>{' '}
                    <span className="text-muted-foreground">
                        {list.contacts_count === 1 ? 'contact' : 'contacts'}
                    </span>
                </p>
                <p className="text-xs text-muted-foreground">
                    {list.contacts_count === 0
                        ? 'Import or add contacts to fill it'
                        : unsubscribed === 0
                          ? 'All subscribed'
                          : `${list.subscribed_count.toLocaleString()} subscribed · ${unsubscribed.toLocaleString()} unsubscribed`}
                </p>
            </div>

            <div className="space-y-1.5 border-t px-5 pt-3 pb-3.5">
                <div className="flex items-baseline justify-between gap-3 text-xs">
                    <span className="text-muted-foreground">
                        Joined in the last {days} days
                    </span>
                    <span
                        className={
                            joined > 0
                                ? 'font-medium text-emerald-700 tabular-nums dark:text-emerald-400'
                                : 'text-muted-foreground'
                        }
                    >
                        {joined > 0 ? `+${joined.toLocaleString()}` : 'None'}
                    </span>
                </div>
                {/* A baseline under the bars, so quiet days still read as days. */}
                <div className="border-b border-border">
                    <Sparkline
                        data={list.joins}
                        label={`Contacts joining ${list.name} per day`}
                        height={28}
                        className="text-emerald-600/70 dark:text-emerald-400/70"
                    />
                </div>
            </div>

            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent>
                    <DialogTitle>Delete {list.name}?</DialogTitle>
                    <DialogDescription>
                        The list is removed. Its contacts are kept, and stay on
                        any other lists they are on.
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(
                                    ContactListController.destroy.url(list.id),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Delete list
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/**
 * Names and describes a new list, or changes an existing one's details.
 */
function ListDialog({
    editing,
    onClose,
}: {
    editing: Editing | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={editing !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                {editing && (
                    <ListForm
                        key={editing.list?.id ?? editing.initial.name}
                        editing={editing}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ListForm({
    editing,
    onClose,
}: {
    editing: Editing;
    onClose: () => void;
}) {
    const form = useForm<ListDetails>(editing.initial);
    const { list } = editing;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            list
                ? ContactListController.update(list.id)
                : ContactListController.store(),
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="space-y-1.5">
                <DialogTitle>{list ? 'Edit list' : 'New list'}</DialogTitle>
                <DialogDescription>
                    {list
                        ? 'Contacts on the list are not affected.'
                        : 'Campaigns can be sent to a list, and contacts can join from imports, the API or by hand.'}
                </DialogDescription>
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="list-name">Name</Label>
                <Input
                    id="list-name"
                    required
                    autoFocus
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="list-description">
                    Description{' '}
                    <span className="font-normal text-muted-foreground">
                        (optional)
                    </span>
                </Label>
                <Textarea
                    id="list-description"
                    rows={2}
                    placeholder="Who is on it, and what they get"
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
                <InputError message={form.errors.description} />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {list ? 'Save list' : 'Create list'}
                </Button>
            </DialogFooter>
        </form>
    );
}

ListsIndex.layout = {
    breadcrumbs: [{ title: 'Lists', href: listsIndex() }],
};
