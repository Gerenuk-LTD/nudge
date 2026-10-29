import { Form, Head, Link } from '@inertiajs/react';
import ContactListController from '@/actions/Gerenuk/Marketing/Http/Controllers/ContactListController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import { index as listsIndex } from '@/routes/marketing/lists';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import type { DailyCount } from '@marketing/components/charts';
import { Sparkline } from '@marketing/components/charts';
import { EditDialog } from '@marketing/components/edit-dialog';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';

type ListRow = {
    id: number;
    name: string;
    description: string | null;
    contacts_count: number;
    joins: DailyCount[];
};

export default function ListsIndex({
    lists,
    days,
}: {
    lists: ListRow[];
    days: number;
}) {
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

                {lists.length === 0 ? (
                    <div className="rounded-xl border border-dashed bg-muted/40 px-6 py-14 text-center text-sm text-muted-foreground">
                        No lists yet. Add one above, then import or add contacts
                        to it.
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {lists.map((list) => (
                            <ListCard
                                key={list.id}
                                list={list}
                                days={days}
                                canEdit={canEdit}
                            />
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

function ListCard({
    list,
    days,
    canEdit,
}: {
    list: ListRow;
    days: number;
    canEdit: boolean;
}) {
    const joined = list.joins.reduce((sum, day) => sum + day.count, 0);

    return (
        <div className="group relative flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-xs transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <Link
                        href={contactsIndex({ query: { list: list.id } })}
                        className="text-sm font-semibold after:absolute after:inset-0 after:rounded-xl"
                    >
                        {list.name}
                    </Link>
                    <p className="truncate text-13 text-muted-foreground">
                        {list.description || 'No description'}
                    </p>
                </div>
                {canEdit && (
                    <div className="relative z-10 -mt-1 -mr-2 flex shrink-0">
                        <EditDialog
                            title="Edit list"
                            action={ContactListController.update.form(list.id)}
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
                            url={ContactListController.destroy.url(list.id)}
                            title={`Delete ${list.name}?`}
                            description="The list is removed. Its contacts are kept."
                        />
                    </div>
                )}
            </div>

            <div className="flex items-end justify-between gap-4">
                <div>
                    <p className="text-2xl font-semibold tabular-nums">
                        {list.contacts_count.toLocaleString()}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {joined > 0
                            ? `+${joined.toLocaleString()} joined in ${days} days`
                            : `No one joined in ${days} days`}
                    </p>
                </div>
                <div className="w-32">
                    <Sparkline
                        data={list.joins}
                        label={`Contacts joining ${list.name} per day`}
                        height={32}
                        className="text-emerald-600/70 dark:text-emerald-400/70"
                    />
                </div>
            </div>
        </div>
    );
}

ListsIndex.layout = {
    breadcrumbs: [{ title: 'Lists', href: listsIndex() }],
};
