import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link } from '@inertiajs/react';
import TagController from '@/actions/Gerenuk/Marketing/Http/Controllers/TagController';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import { index as tagsIndex } from '@/routes/marketing/tags';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { byId, DataTable } from '@marketing/components/data-table';
import { EditDialog } from '@marketing/components/edit-dialog';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';

type TagRow = { id: number; name: string; contacts_count: number };

export default function TagsIndex({ tags }: { tags: TagRow[] }) {
    const canEdit = useCan()('contacts.manage');

    const columns: ColumnDef<TagRow>[] = [
        {
            accessorKey: 'name',
            header: 'Tag',
            cell: ({ row: { original: tag } }) => (
                <Link
                    href={contactsIndex({ query: { tag: tag.id } })}
                    className="font-medium hover:underline"
                >
                    {tag.name}
                </Link>
            ),
        },
        {
            accessorKey: 'contacts_count',
            header: 'Contacts',
            meta: { numeric: true },
            cell: ({ row }) => row.original.contacts_count.toLocaleString(),
        },
        {
            id: 'actions',
            header: '',
            meta: { className: 'text-right whitespace-nowrap' },
            cell: ({ row: { original: tag } }) =>
                canEdit && (
                    <>
                        <EditDialog
                            title="Rename tag"
                            trigger="Rename"
                            action={TagController.update.form(tag.id)}
                            fields={[
                                {
                                    name: 'name',
                                    label: 'Name',
                                    value: tag.name,
                                },
                            ]}
                        />
                        <ConfirmDelete
                            url={TagController.destroy.url(tag.id)}
                            title={`Delete ${tag.name}?`}
                            description="The tag is removed from every contact that has it."
                        />
                    </>
                ),
        },
    ];

    return (
        <>
            <Head title="Tags" />

            <PageBody>
                <PageHeader
                    title="Tags"
                    description="Tags are created when you add them to a contact, an import or through the API."
                />

                <DataTable
                    columns={columns}
                    data={tags}
                    getRowId={byId}
                    sortable
                    emptyMessage="No tags yet."
                />
            </PageBody>
        </>
    );
}

TagsIndex.layout = {
    breadcrumbs: [{ title: 'Tags', href: tagsIndex() }],
};
