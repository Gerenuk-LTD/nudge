import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { MouseEvent } from 'react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    create as createContact,
    index as contactsIndex,
    show as showContact,
} from '@/routes/marketing/contacts';
import type { ContactPreviewData } from '@marketing/components/contact-preview';
import { ContactPreview } from '@marketing/components/contact-preview';
import { byId, DataTable } from '@marketing/components/data-table';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { StatusBadge } from '@marketing/components/status-badge';
import { Pagination } from '@marketing/components/pagination';
import {
    ChoiceFilter,
    FacetedFilter,
    ResetFilters,
    TableSearch,
} from '@marketing/components/table-toolbar';
import { useTableQuery } from '@marketing/hooks/use-table-query';
import { useCan } from '@marketing/hooks/use-workspace';
import type { ContactStatus, Option, Paginated } from '@marketing/types';
import { formatDate } from '@marketing/lib/format';

type ContactRow = {
    id: number;
    email: string;
    name: string | null;
    status: ContactStatus;
    tags: string[];
    created_at: string;
};

/** The filters the toolbar sets, cleared together by Reset. */
const FILTERS = ['segment', 'list', 'tag', 'status', 'archived'];

const options = (items: Option[]) =>
    items.map((item) => ({ value: String(item.id), label: item.name }));

export default function ContactsIndex({
    contacts,
    lists,
    tags,
    segments,
    preview,
}: {
    contacts: Paginated<ContactRow>;
    lists: Option[];
    tags: Option[];
    segments: Option[];
    preview?: ContactPreviewData | null;
}) {
    const can = useCan();
    const table = useTableQuery();
    const filtered = FILTERS.some((key) => table.get(key) !== '');
    const [previewId, setPreviewId] = useState<number | null>(null);

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
            header: 'Email',
            meta: { sortable: true },
            cell: ({ row }) => (
                <Link
                    href={showContact(row.original.id)}
                    onClick={(event) => openPreview(event, row.original.id)}
                    className="font-medium hover:underline"
                >
                    {row.original.email}
                </Link>
            ),
        },
        {
            id: 'name',
            header: 'Name',
            meta: { className: 'text-muted-foreground', sortable: true },
            cell: ({ row }) => row.original.name ?? '—',
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
                    {row.original.tags.map((tag) => (
                        <Badge key={tag} variant="outline">
                            {tag}
                        </Badge>
                    ))}
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
    ];

    return (
        <>
            <Head title="Contacts" />

            <PageBody>
                <PageHeader
                    title="Contacts"
                    description={`${contacts.total.toLocaleString()} ${table.get('archived') ? 'archived ' : ''}contacts`}
                    actions={
                        can('contacts.manage') && (
                            <Button asChild>
                                <Link href={createContact()}>
                                    <Plus />
                                    Add contact
                                </Link>
                            </Button>
                        )
                    }
                />

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
                        onChange={(segment) => table.update({ segment })}
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
                            { value: 'subscribed', label: 'Subscribed' },
                            { value: 'unsubscribed', label: 'Unsubscribed' },
                        ]}
                        selected={table.values('status')}
                        onChange={(status) => table.update({ status })}
                    />
                    <ChoiceFilter
                        title="Archived"
                        options={[{ value: '1', label: 'Archived only' }]}
                        value={table.get('archived')}
                        onChange={(archived) => table.update({ archived })}
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
            </PageBody>

            <ContactPreview
                open={previewId !== null}
                contact={preview?.id === previewId ? preview : null}
                onClose={() => setPreviewId(null)}
            />
        </>
    );
}

function ContactStatusBadge({ status }: { status: ContactStatus }) {
    return (
        <StatusBadge tone={status === 'subscribed' ? 'success' : 'neutral'}>
            {status === 'subscribed' ? 'Subscribed' : 'Unsubscribed'}
        </StatusBadge>
    );
}

ContactsIndex.layout = {
    breadcrumbs: [{ title: 'Contacts', href: contactsIndex() }],
};
