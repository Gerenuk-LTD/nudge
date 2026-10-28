import { Head, Link, usePoll } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    create,
    index as importsIndex,
    show,
} from '@/routes/marketing/imports';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { ImportStatusBadge } from '@marketing/components/import-status-badge';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import type { ImportSummary } from '@marketing/types';

export default function ImportsIndex({
    imports,
}: {
    imports: ImportSummary[];
}) {
    const running = imports.some((item) =>
        ['queued', 'processing'].includes(item.status),
    );

    usePoll(3000, { only: ['imports'] }, { autoStart: running });

    return (
        <>
            <Head title="Imports" />

            <PageBody>
                <PageHeader
                    title="Imports"
                    description="Bring contacts in from a CSV file. Existing contacts are updated, never duplicated."
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Upload />
                                Import CSV
                            </Link>
                        </Button>
                    }
                />

                <DataTable
                    headers={[
                        'File',
                        'Status',
                        'Created',
                        'Updated',
                        'Failed',
                        'Started',
                    ]}
                    empty={
                        imports.length === 0 && (
                            <EmptyRow message="No imports yet." />
                        )
                    }
                >
                    {imports.map((item) => (
                        <tr key={item.id}>
                            <Cell>
                                <Link
                                    href={show(item.id)}
                                    className="font-medium hover:underline"
                                >
                                    {item.original_filename}
                                </Link>
                            </Cell>
                            <Cell>
                                <ImportStatusBadge status={item.status} />
                            </Cell>
                            <Cell>{item.created_count.toLocaleString()}</Cell>
                            <Cell>{item.updated_count.toLocaleString()}</Cell>
                            <Cell>{item.failed_count.toLocaleString()}</Cell>
                            <Cell className="text-muted-foreground">
                                {new Date(item.created_at).toLocaleString()}
                            </Cell>
                        </tr>
                    ))}
                </DataTable>
            </PageBody>
        </>
    );
}

ImportsIndex.layout = {
    breadcrumbs: [{ title: 'Imports', href: importsIndex() }],
};
