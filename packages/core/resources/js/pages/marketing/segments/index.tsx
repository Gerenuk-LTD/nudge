import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import SegmentController from '@/actions/Gerenuk/Marketing/Http/Controllers/SegmentController';
import { Button } from '@/components/ui/button';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import {
    create,
    edit,
    index as segmentsIndex,
} from '@/routes/marketing/segments';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';

type SegmentRow = {
    id: number;
    name: string;
    contacts_count: number;
    updated_at: string | null;
};

export default function SegmentsIndex({
    segments,
}: {
    segments: SegmentRow[];
}) {
    const canEdit = useCan()('segments.manage');

    return (
        <>
            <Head title="Segments" />

            <PageBody>
                <PageHeader
                    title="Segments"
                    description="Saved filters over your contacts, by details, tags and lists."
                    actions={
                        canEdit && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus />
                                    New segment
                                </Link>
                            </Button>
                        )
                    }
                />

                <DataTable
                    headers={['Name', 'Contacts', 'Updated', '']}
                    empty={
                        segments.length === 0 && (
                            <EmptyRow message="No segments yet." />
                        )
                    }
                >
                    {segments.map((segment) => (
                        <tr key={segment.id}>
                            <Cell className="font-medium">
                                {canEdit ? (
                                    <Link
                                        href={edit(segment.id)}
                                        className="hover:underline"
                                    >
                                        {segment.name}
                                    </Link>
                                ) : (
                                    segment.name
                                )}
                            </Cell>
                            <Cell>
                                <Link
                                    href={contactsIndex({
                                        query: { segment: segment.id },
                                    })}
                                    className="hover:underline"
                                >
                                    {segment.contacts_count.toLocaleString()}
                                </Link>
                            </Cell>
                            <Cell className="text-muted-foreground">
                                {segment.updated_at &&
                                    new Date(
                                        segment.updated_at,
                                    ).toLocaleDateString()}
                            </Cell>
                            <Cell className="text-right">
                                {canEdit && (
                                    <ConfirmDelete
                                        url={SegmentController.destroy.url(
                                            segment.id,
                                        )}
                                        title={`Delete ${segment.name}?`}
                                        description="The segment is deleted. No contacts are changed."
                                    />
                                )}
                            </Cell>
                        </tr>
                    ))}
                </DataTable>
            </PageBody>
        </>
    );
}

SegmentsIndex.layout = {
    breadcrumbs: [{ title: 'Segments', href: segmentsIndex() }],
};
