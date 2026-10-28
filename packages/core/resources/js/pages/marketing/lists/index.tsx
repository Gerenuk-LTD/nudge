import { Form, Head, Link } from '@inertiajs/react';
import ContactListController from '@/actions/Gerenuk/Marketing/Http/Controllers/ContactListController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import { index as listsIndex } from '@/routes/marketing/lists';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { EditDialog } from '@marketing/components/edit-dialog';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';

type ListRow = {
    id: number;
    name: string;
    description: string | null;
    contacts_count: number;
};

export default function ListsIndex({ lists }: { lists: ListRow[] }) {
    const canEdit = useCan()('contacts.manage');

    return (
        <>
            <Head title="Lists" />

            <PageBody>
                <PageHeader
                    title="Lists"
                    description="Group contacts by what they signed up for. A contact can be on many lists."
                />

                {canEdit && (
                    <Form
                        {...ContactListController.store.form()}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="flex max-w-xl flex-wrap items-start gap-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="min-w-56 flex-1 space-y-1">
                                    <Input
                                        name="name"
                                        placeholder="New list name"
                                        aria-label="New list name"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <Button disabled={processing}>Add list</Button>
                            </>
                        )}
                    </Form>
                )}

                <DataTable
                    headers={['Name', 'Contacts', '']}
                    empty={
                        lists.length === 0 && (
                            <EmptyRow message="No lists yet." />
                        )
                    }
                >
                    {lists.map((list) => (
                        <tr key={list.id}>
                            <Cell>
                                <Link
                                    href={contactsIndex({
                                        query: { list: list.id },
                                    })}
                                    className="font-medium hover:underline"
                                >
                                    {list.name}
                                </Link>
                                {list.description && (
                                    <p className="text-xs text-muted-foreground">
                                        {list.description}
                                    </p>
                                )}
                            </Cell>
                            <Cell>{list.contacts_count.toLocaleString()}</Cell>
                            <Cell className="text-right whitespace-nowrap">
                                {canEdit && (
                                    <>
                                        <EditDialog
                                            title="Edit list"
                                            action={ContactListController.update.form(
                                                list.id,
                                            )}
                                            fields={[
                                                {
                                                    name: 'name',
                                                    label: 'Name',
                                                    value: list.name,
                                                },
                                                {
                                                    name: 'description',
                                                    label: 'Description',
                                                    value: list.description,
                                                },
                                            ]}
                                        />
                                        <ConfirmDelete
                                            url={ContactListController.destroy.url(
                                                list.id,
                                            )}
                                            title={`Delete ${list.name}?`}
                                            description="The list is removed. Its contacts are kept."
                                        />
                                    </>
                                )}
                            </Cell>
                        </tr>
                    ))}
                </DataTable>
            </PageBody>
        </>
    );
}

ListsIndex.layout = {
    breadcrumbs: [{ title: 'Lists', href: listsIndex() }],
};
