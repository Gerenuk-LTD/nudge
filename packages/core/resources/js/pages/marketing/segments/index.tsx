import { Head, Link } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
import SegmentController from '@/actions/Gerenuk/Marketing/Http/Controllers/SegmentController';
import { Button } from '@/components/ui/button';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import {
    create,
    edit,
    index as segmentsIndex,
} from '@/routes/marketing/segments';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDate } from '@marketing/lib/format';

type SegmentRow = {
    id: number;
    name: string;
    contacts_count: number;
    match: 'all' | 'any';
    fields: string[];
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

                {segments.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center">
                        <Filter className="size-5 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            No segments yet. A segment keeps itself up to date
                            as contacts change.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {segments.map((segment) => (
                            <SegmentCard
                                key={segment.id}
                                segment={segment}
                                canEdit={canEdit}
                            />
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

function SegmentCard({
    segment,
    canEdit,
}: {
    segment: SegmentRow;
    canEdit: boolean;
}) {
    return (
        <div className="group relative flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-xs transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <Link
                        href={contactsIndex({
                            query: { segment: segment.id },
                        })}
                        className="text-sm font-semibold after:absolute after:inset-0 after:rounded-xl"
                    >
                        {segment.name}
                    </Link>
                    <p className="truncate text-13 text-muted-foreground">
                        {segment.fields.length === 0
                            ? 'Everyone'
                            : segment.fields.length === 1
                              ? `Filters on ${segment.fields[0]}`
                              : `Matches ${segment.match} of ${segment.fields.join(', ')}`}
                    </p>
                </div>
                {canEdit && (
                    <div className="relative z-10 -mt-1 -mr-2 flex shrink-0">
                        <ConfirmDelete
                            url={SegmentController.destroy.url(segment.id)}
                            title={`Delete ${segment.name}?`}
                            description="The segment is deleted. No contacts are changed."
                        />
                    </div>
                )}
            </div>

            <div className="flex items-end justify-between gap-4">
                <div>
                    <p className="text-2xl font-semibold tabular-nums">
                        {segment.contacts_count.toLocaleString()}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {segment.contacts_count === 1 ? 'contact' : 'contacts'}
                        {segment.updated_at &&
                            ` · edited ${formatDate(segment.updated_at)}`}
                    </p>
                </div>
                {canEdit && (
                    <Button
                        variant="outline"
                        size="sm"
                        className="relative z-10"
                        asChild
                    >
                        <Link href={edit(segment.id)}>Edit rules</Link>
                    </Button>
                )}
            </div>
        </div>
    );
}

SegmentsIndex.layout = {
    breadcrumbs: [{ title: 'Segments', href: segmentsIndex() }],
};
