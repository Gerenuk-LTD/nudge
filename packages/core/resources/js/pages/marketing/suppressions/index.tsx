import type { ColumnDef } from '@tanstack/react-table';
import { Form, Head } from '@inertiajs/react';
import SuppressionController from '@/actions/Gerenuk/Marketing/Http/Controllers/SuppressionController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as suppressionsIndex } from '@/routes/marketing/suppressions';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { byId, DataTable } from '@marketing/components/data-table';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { StatusBadge } from '@marketing/components/status-badge';
import { Pagination } from '@marketing/components/pagination';
import {
    FacetedFilter,
    ResetFilters,
    TableSearch,
} from '@marketing/components/table-toolbar';
import { useTableQuery } from '@marketing/hooks/use-table-query';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDate } from '@marketing/lib/format';
import type { Paginated } from '@marketing/types';

type Suppression = {
    id: number;
    email: string;
    reason: string;
    detail: string | null;
    created_at: string | null;
};

const REASONS = [
    { value: 'bounce', label: 'Bounce' },
    { value: 'complaint', label: 'Complaint' },
    { value: 'manual', label: 'Manual' },
];

export default function SuppressionsIndex({
    suppressions,
}: {
    suppressions: Paginated<Suppression>;
}) {
    const canEdit = useCan()('contacts.manage');
    const table = useTableQuery();

    const columns: ColumnDef<Suppression>[] = [
        {
            id: 'email',
            header: 'Email',
            meta: { className: 'font-medium', sortable: true },
            cell: ({ row }) => row.original.email,
        },
        {
            id: 'reason',
            header: 'Reason',
            meta: { sortable: true },
            cell: ({ row: { original: suppression } }) => (
                <>
                    <StatusBadge
                        tone={
                            suppression.reason === 'manual'
                                ? 'neutral'
                                : 'danger'
                        }
                    >
                        {REASONS.find(
                            (reason) => reason.value === suppression.reason,
                        )?.label ?? suppression.reason}
                    </StatusBadge>
                    {suppression.detail && (
                        <p className="text-xs text-muted-foreground">
                            {suppression.detail}
                        </p>
                    )}
                </>
            ),
        },
        {
            id: 'since',
            header: 'Since',
            meta: { className: 'text-muted-foreground', sortable: true },
            cell: ({ row }) => formatDate(row.original.created_at),
        },
        {
            id: 'actions',
            header: '',
            meta: { className: 'text-right' },
            cell: ({ row: { original: suppression } }) =>
                canEdit && (
                    <ConfirmDelete
                        url={SuppressionController.destroy.url(suppression.id)}
                        trigger="Remove"
                        title={`Email ${suppression.email} again?`}
                        description={
                            suppression.reason === 'manual'
                                ? 'The address can receive campaigns again.'
                                : 'This address bounced or complained. Sending to it again can hurt your sender reputation. Only remove it if you know the problem is fixed.'
                        }
                    />
                ),
        },
    ];

    return (
        <>
            <Head title="Suppressions" />

            <PageBody>
                <PageHeader
                    title="Suppressions"
                    description="Addresses that are never emailed: hard bounces, spam complaints and ones you block."
                />

                <div className="flex flex-wrap items-start gap-2">
                    <TableSearch
                        value={table.get('search')}
                        onSearch={(search) => table.update({ search })}
                        placeholder="Search by email"
                    />
                    <FacetedFilter
                        title="Reason"
                        options={REASONS}
                        selected={table.values('reason')}
                        onChange={(reason) => table.update({ reason })}
                    />
                    <ResetFilters
                        active={table.values('reason').length > 0}
                        onReset={() => table.update({ reason: null })}
                    />
                    {canEdit && (
                        <Form
                            {...SuppressionController.store.form()}
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="ml-auto flex items-start gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="space-y-1">
                                        <Input
                                            name="email"
                                            type="email"
                                            placeholder="Block an address"
                                            className="h-8"
                                            aria-label="Address to block"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-8"
                                        disabled={processing}
                                    >
                                        Block
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>

                <DataTable
                    columns={columns}
                    data={suppressions.data}
                    getRowId={byId}
                    emptyMessage="No suppressed addresses."
                    sort={table.sort}
                />

                <Pagination page={suppressions} query={table} />
            </PageBody>
        </>
    );
}

SuppressionsIndex.layout = {
    breadcrumbs: [{ title: 'Suppressions', href: suppressionsIndex() }],
};
