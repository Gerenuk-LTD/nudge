import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import SuppressionController from '@/actions/Gerenuk/Marketing/Http/Controllers/SuppressionController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as suppressionsIndex } from '@/routes/marketing/suppressions';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { Pagination } from '@marketing/components/pagination';
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

export default function SuppressionsIndex({
    suppressions,
    search,
}: {
    suppressions: Paginated<Suppression>;
    search: string | null;
}) {
    const canEdit = useCan()('contacts.manage');
    const [query, setQuery] = useState(search ?? '');

    return (
        <>
            <Head title="Suppressions" />

            <PageBody>
                <PageHeader
                    title="Suppressions"
                    description="Addresses that are never emailed: hard bounces, spam complaints and ones you block."
                />

                <div className="flex flex-wrap items-start gap-2">
                    <form
                        className="min-w-56 flex-1"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                suppressionsIndex.url(),
                                query ? { search: query } : {},
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <Input
                            type="search"
                            placeholder="Search by email"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </form>
                    {canEdit && (
                        <Form
                            {...SuppressionController.store.form()}
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="flex items-start gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="space-y-1">
                                        <Input
                                            name="email"
                                            type="email"
                                            placeholder="Block an address"
                                            aria-label="Address to block"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <Button
                                        variant="outline"
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
                    headers={['Email', 'Reason', 'Since', '']}
                    empty={
                        suppressions.data.length === 0 && (
                            <EmptyRow message="No suppressed addresses." />
                        )
                    }
                >
                    {suppressions.data.map((suppression) => (
                        <tr key={suppression.id}>
                            <Cell className="font-medium">
                                {suppression.email}
                            </Cell>
                            <Cell>
                                <Badge variant="outline">
                                    {suppression.reason}
                                </Badge>
                                {suppression.detail && (
                                    <p className="text-xs text-muted-foreground">
                                        {suppression.detail}
                                    </p>
                                )}
                            </Cell>
                            <Cell className="text-muted-foreground">
                                {formatDate(suppression.created_at)}
                            </Cell>
                            <Cell className="text-right">
                                {canEdit && (
                                    <ConfirmDelete
                                        url={SuppressionController.destroy.url(
                                            suppression.id,
                                        )}
                                        trigger="Remove"
                                        title={`Email ${suppression.email} again?`}
                                        description={
                                            suppression.reason === 'manual'
                                                ? 'The address can receive campaigns again.'
                                                : 'This address bounced or complained. Sending to it again can hurt your sender reputation. Only remove it if you know the problem is fixed.'
                                        }
                                    />
                                )}
                            </Cell>
                        </tr>
                    ))}
                </DataTable>

                <Pagination page={suppressions} />
            </PageBody>
        </>
    );
}

SuppressionsIndex.layout = {
    breadcrumbs: [{ title: 'Suppressions', href: suppressionsIndex() }],
};
