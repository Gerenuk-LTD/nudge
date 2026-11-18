import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    Ellipsis,
    Plus,
    Upload,
    UserCheck,
    UserMinus,
    Users,
} from 'lucide-react';
import type { MouseEvent } from 'react';
import { useState } from 'react';
import ContactController from '@/actions/Gerenuk/Nudge/Http/Controllers/ContactController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    index as contactsIndex,
    show as showContact,
} from '@/routes/nudge/contacts';
import { create as createImport } from '@/routes/nudge/imports';
import { EmptyState } from '@nudge/components/empty-state';
import { AddContactDialog } from '@nudge/components/add-contact-dialog';
import { ContactAvatar } from '@nudge/components/contact-fields';
import type { ContactPreviewData } from '@nudge/components/contact-preview';
import { ContactPreview } from '@nudge/components/contact-preview';
import { byId, DataTable } from '@nudge/components/data-table';
import { PageBody, PageHeader } from '@nudge/components/page-header';
import { ContactStatusBadge } from '@nudge/components/contact-status-badge';
import { SubscriptionDialog } from '@nudge/components/subscription-dialog';
import type { SubscriptionTarget } from '@nudge/components/subscription-dialog';
import { Pagination } from '@nudge/components/pagination';
import {
    ChoiceFilter,
    FacetedFilter,
    ResetFilters,
    TableSearch,
} from '@nudge/components/table-toolbar';
import { useTableQuery } from '@nudge/hooks/use-table-query';
import { useCan } from '@nudge/hooks/use-workspace';
import type {
    ContactStatus,
    CustomField,
    Option,
    Paginated,
} from '@nudge/types';
import { formatDate } from '@nudge/lib/format';

type ContactRow = {
    id: number;
    email: string;
    name: string | null;
    status: ContactStatus;
    tags: string[];
    created_at: string;
};

/** Tags shown in a row before the rest are summed up as "+n". */
const VISIBLE_TAGS = 3;

/** The filters the toolbar sets, cleared together by Reset. */
const FILTERS = ['segment', 'list', 'tag', 'status', 'archived'];

const options = (items: Option[]) =>
    items.map((item) => ({ value: String(item.id), label: item.name }));

export default function ContactsIndex({
    contacts,
    lists,
    tags,
    segments,
    customFields,
    preview,
}: {
    contacts: Paginated<ContactRow>;
    lists: Option[];
    tags: Option[];
    segments: Option[];
    customFields: CustomField[];
    preview?: ContactPreviewData | null;
}) {
    const can = useCan();
    const table = useTableQuery();
    const filtered = FILTERS.some((key) => table.get(key) !== '');
    const [previewId, setPreviewId] = useState<number | null>(null);
    const [changing, setChanging] = useState<SubscriptionTarget | null>(null);
    const [adding, setAdding] = useState(table.get('add') !== '');
    const empty =
        contacts.total === 0 && !filtered && table.get('search') === '';

    /** Closing the dialog also drops `?add=1`, which links such as the command palette open it with. */
    const setAddingOpen = (open: boolean) => {
        setAdding(open);

        if (!open && table.get('add') !== '') {
            table.update({ add: null });
        }
    };

    /** A plain click previews the contact in the side panel; modified clicks still open the full profile. */
    const openPreview = (event: MouseEvent, id: number) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        setPreviewId(id);
        router.reload({
            only: ['preview'],
            data: { preview: id },
            preserveUrl: true,
        });
    };

    const columns: ColumnDef<ContactRow>[] = [
        {
            id: 'email',
            header: 'Contact',
            meta: { sortable: true },
            cell: ({ row }) => (
                <Link
                    href={showContact(row.original.id)}
                    onClick={(event) => openPreview(event, row.original.id)}
                    className="group flex min-w-0 items-center gap-3"
                >
                    <ContactAvatar
                        name={row.original.name}
                        email={row.original.email}
                    />
                    <span className="grid min-w-0 leading-tight">
                        <span className="truncate font-medium group-hover:underline">
                            {row.original.name ?? row.original.email}
                        </span>
                        {row.original.name && (
                            <span className="truncate text-xs text-muted-foreground">
                                {row.original.email}
                            </span>
                        )}
                    </span>
                </Link>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            meta: { sortable: true },
            cell: ({ row }) => (
                <ContactStatusBadge status={row.original.status} />
            ),
        },
        {
            id: 'tags',
            header: 'Tags',
            cell: ({ row }) => (
                <div className="flex flex-wrap gap-1">
                    {row.original.tags.slice(0, VISIBLE_TAGS).map((tag) => (
                        <Badge key={tag} variant="outline">
                            {tag}
                        </Badge>
                    ))}
                    {row.original.tags.length > VISIBLE_TAGS && (
                        <Badge
                            variant="outline"
                            title={row.original.tags
                                .slice(VISIBLE_TAGS)
                                .join(', ')}
                            className="text-muted-foreground"
                        >
                            +{row.original.tags.length - VISIBLE_TAGS}
                        </Badge>
                    )}
                </div>
            ),
        },
        {
            id: 'added',
            header: 'Added',
            meta: {
                className: 'whitespace-nowrap text-muted-foreground',
                sortable: true,
            },
            cell: ({ row }) => formatDate(row.original.created_at),
        },
        ...(can('contacts.manage')
            ? [
                  {
                      id: 'actions',
                      header: () => <span className="sr-only">Actions</span>,
                      meta: { className: 'w-px' },
                      cell: ({ row }) => (
                          <ContactActions
                              contact={row.original}
                              onChangeSubscription={setChanging}
                          />
                      ),
                  } satisfies ColumnDef<ContactRow>,
              ]
            : []),
    ];

    return (
        <>
            <Head title="Contacts" />

            <PageBody>
                <PageHeader
                    title="Contacts"
                    description={`${contacts.total.toLocaleString()} ${table.get('archived') ? 'archived ' : ''}contacts`}
                    actions={
                        <>
                            {can('contacts.import') && (
                                <Button variant="outline" asChild>
                                    <Link href={createImport()}>
                                        <Upload />
                                        Import
                                    </Link>
                                </Button>
                            )}
                            {can('contacts.manage') && (
                                <Button onClick={() => setAddingOpen(true)}>
                                    <Plus />
                                    Add contact
                                </Button>
                            )}
                        </>
                    }
                />

                {empty ? (
                    <EmptyState
                        icon={Users}
                        title="No contacts yet"
                        description="Import a CSV from another tool, or add people one at a time."
                        actions={
                            <>
                                {can('contacts.import') && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={createImport()}>
                                            <Upload />
                                            Import a CSV
                                        </Link>
                                    </Button>
                                )}
                                {can('contacts.manage') && (
                                    <Button
                                        size="sm"
                                        onClick={() => setAddingOpen(true)}
                                    >
                                        <Plus />
                                        Add contact
                                    </Button>
                                )}
                            </>
                        }
                    />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <TableSearch
                                value={table.get('search')}
                                onSearch={(search) => table.update({ search })}
                                placeholder="Search email or name"
                            />
                            <ChoiceFilter
                                title="Segment"
                                options={options(segments)}
                                value={table.get('segment')}
                                onChange={(segment) =>
                                    table.update({ segment })
                                }
                            />
                            <FacetedFilter
                                title="List"
                                options={options(lists)}
                                selected={table.values('list')}
                                onChange={(list) => table.update({ list })}
                            />
                            <FacetedFilter
                                title="Tag"
                                options={options(tags)}
                                selected={table.values('tag')}
                                onChange={(tag) => table.update({ tag })}
                            />
                            <FacetedFilter
                                title="Status"
                                options={[
                                    {
                                        value: 'subscribed',
                                        label: 'Subscribed',
                                    },
                                    {
                                        value: 'unsubscribed',
                                        label: 'Unsubscribed',
                                    },
                                ]}
                                selected={table.values('status')}
                                onChange={(status) => table.update({ status })}
                            />
                            <ChoiceFilter
                                title="Archived"
                                options={[
                                    { value: '1', label: 'Archived only' },
                                ]}
                                value={table.get('archived')}
                                onChange={(archived) =>
                                    table.update({ archived })
                                }
                            />
                            <ResetFilters
                                active={filtered}
                                onReset={() =>
                                    table.update(
                                        Object.fromEntries(
                                            FILTERS.map((key) => [key, null]),
                                        ),
                                    )
                                }
                            />
                        </div>

                        <DataTable
                            columns={columns}
                            data={contacts.data}
                            getRowId={byId}
                            emptyMessage="No contacts match these filters."
                            sort={table.sort}
                        />

                        <Pagination page={contacts} query={table} />
                    </>
                )}
            </PageBody>

            {can('contacts.manage') && (
                <AddContactDialog
                    open={adding}
                    onOpenChange={setAddingOpen}
                    lists={lists}
                    tags={tags.map((tag) => tag.name)}
                    customFields={customFields}
                />
            )}

            <ContactPreview
                open={previewId !== null}
                contact={preview?.id === previewId ? preview : null}
                onClose={() => setPreviewId(null)}
                onChangeSubscription={
                    can('contacts.manage') ? setChanging : undefined
                }
            />

            <SubscriptionDialog
                contact={changing}
                onClose={() => setChanging(null)}
                onDone={(contact) =>
                    contact.id === previewId &&
                    router.reload({
                        only: ['preview'],
                        data: { preview: contact.id },
                        preserveUrl: true,
                    })
                }
            />
        </>
    );
}

function ContactActions({
    contact,
    onChangeSubscription,
}: {
    contact: ContactRow;
    onChangeSubscription: (contact: SubscriptionTarget) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground"
                    aria-label={`Actions for ${contact.email}`}
                >
                    <Ellipsis />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    onSelect={() => onChangeSubscription(contact)}
                >
                    {contact.status === 'subscribed' ? (
                        <>
                            <UserMinus />
                            Unsubscribe
                        </>
                    ) : (
                        <>
                            <UserCheck />
                            Subscribe again
                        </>
                    )}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={() =>
                        router.post(
                            ContactController.archive.url(contact.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <Archive />
                    Archive
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

ContactsIndex.layout = {
    breadcrumbs: [{ title: 'Contacts', href: contactsIndex() }],
};
