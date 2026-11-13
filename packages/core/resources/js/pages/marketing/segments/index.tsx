import { Head, Link, router } from '@inertiajs/react';
import { Ellipsis, Filter, Pencil, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import SegmentController from '@/actions/Gerenuk/Marketing/Http/Controllers/SegmentController';
import { ConfirmDialog } from '@marketing/components/confirm-dialog';
import { EmptyState } from '@marketing/components/empty-state';
import { StarterCard, StarterGrid } from '@marketing/components/starter-grid';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import {
    create,
    edit,
    index as segmentsIndex,
} from '@/routes/marketing/segments';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import {
    describeGroup,
    segmentStarters,
} from '@marketing/components/segment-builder';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDate } from '@marketing/lib/format';
import type { SegmentField, SegmentGroup } from '@marketing/types';

type SegmentRow = {
    id: number;
    name: string;
    definition: SegmentGroup;
    contacts_count: number;
    updated_at: string | null;
};

/** Rules shown on a card before the rest are summed up as "and 2 more". */
const VISIBLE_RULES = 3;

export default function SegmentsIndex({
    segments,
    activeContacts,
    fields,
}: {
    segments: SegmentRow[];
    activeContacts: number;
    fields: SegmentField[];
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
                        canEdit &&
                        segments.length > 0 && (
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
                    <EmptySegments canEdit={canEdit} fields={fields} />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {segments.map((segment) => (
                            <SegmentCard
                                key={segment.id}
                                segment={segment}
                                fields={fields}
                                activeContacts={activeContacts}
                                canEdit={canEdit}
                            />
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

function EmptySegments({
    canEdit,
    fields,
}: {
    canEdit: boolean;
    fields: SegmentField[];
}) {
    return (
        <EmptyState
            icon={Filter}
            title="No segments yet"
            description="A segment picks contacts by their details, tags and lists, and keeps itself up to date as contacts change. Send campaigns to it, or use it to filter your contacts."
            actions={
                canEdit && (
                    <>
                        <Button size="sm" asChild>
                            <Link href={create()}>
                                <Plus />
                                New segment
                            </Link>
                        </Button>
                        <StarterGrid>
                            {segmentStarters(fields).map((starter) => (
                                <StarterCard
                                    key={starter.key}
                                    title={starter.name}
                                    description={starter.description}
                                    href={create({
                                        query: { starter: starter.key },
                                    })}
                                />
                            ))}
                        </StarterGrid>
                    </>
                )
            }
        />
    );
}

function SegmentCard({
    segment,
    fields,
    activeContacts,
    canEdit,
}: {
    segment: SegmentRow;
    fields: SegmentField[];
    activeContacts: number;
    canEdit: boolean;
}) {
    const [deleting, setDeleting] = useState(false);
    const rules = describeGroup(segment.definition, fields);
    const join = segment.definition.match === 'all' ? 'and' : 'or';
    const share =
        activeContacts > 0 ? segment.contacts_count / activeContacts : 0;
    const contacts = contactsIndex({ query: { segment: segment.id } });

    return (
        <div className="group relative flex flex-col rounded-xl border bg-card shadow-xs transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm">
            <div className="flex items-start justify-between gap-3 px-5 pt-4">
                <Link
                    href={contacts}
                    className="min-w-0 truncate pt-1 text-sm font-semibold after:absolute after:inset-0 after:rounded-xl"
                >
                    {segment.name}
                </Link>
                {canEdit && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="relative z-10 -mr-2 size-8 text-muted-foreground"
                                aria-label={`Actions for ${segment.name}`}
                            >
                                <Ellipsis />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                                <Link href={edit(segment.id)}>
                                    <Pencil />
                                    Edit rules
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <Link href={contacts}>
                                    <Users />
                                    View contacts
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => setDeleting(true)}
                            >
                                <Trash2 />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            <ul className="flex-1 space-y-1 px-5 pt-2 pb-4 text-13 text-muted-foreground">
                {rules.length === 0 ? (
                    <li>Every active contact</li>
                ) : (
                    rules.slice(0, VISIBLE_RULES).map((rule, index) => (
                        <li key={index} className="truncate" title={rule}>
                            {index > 0 && (
                                <span className="mr-1 font-medium text-foreground">
                                    {join}
                                </span>
                            )}
                            {rule}
                        </li>
                    ))
                )}
                {rules.length > VISIBLE_RULES && (
                    <li className="text-xs">
                        {join} {rules.length - VISIBLE_RULES} more
                    </li>
                )}
            </ul>

            <div className="space-y-2.5 border-t px-5 py-3.5">
                <div className="flex items-baseline justify-between gap-3">
                    <p className="text-sm">
                        <span className="text-lg font-semibold tabular-nums">
                            {segment.contacts_count.toLocaleString()}
                        </span>{' '}
                        <span className="text-muted-foreground">
                            {segment.contacts_count === 1
                                ? 'contact'
                                : 'contacts'}{' '}
                            · {Math.round(share * 100)}%
                        </span>
                    </p>
                    {segment.updated_at && (
                        <p className="shrink-0 text-xs text-muted-foreground">
                            Edited {formatDate(segment.updated_at)}
                        </p>
                    )}
                </div>
                <div
                    className="h-1.5 overflow-hidden rounded-full bg-muted"
                    role="presentation"
                >
                    <div
                        className="h-full rounded-full bg-foreground/70"
                        style={{
                            width: `${Math.max(share * 100, share > 0 ? 2 : 0)}%`,
                        }}
                    />
                </div>
            </div>

            <ConfirmDialog
                open={deleting}
                onOpenChange={setDeleting}
                title={<>Delete {segment.name}?</>}
                description="The segment is deleted. No contacts are changed."
                confirmLabel="Delete segment"
                onConfirm={() =>
                    router.delete(SegmentController.destroy.url(segment.id), {
                        preserveScroll: true,
                    })
                }
            />
        </div>
    );
}

SegmentsIndex.layout = {
    breadcrumbs: [{ title: 'Segments', href: segmentsIndex() }],
};
