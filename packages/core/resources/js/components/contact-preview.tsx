import { Link } from '@inertiajs/react';
import { ArrowRight, UserCheck, UserMinus } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { show as showCampaign } from '@/routes/marketing/campaigns';
import { show as showContact } from '@/routes/marketing/contacts';
import { ContactStatusBadge } from '@marketing/components/contact-status-badge';
import { StatusBadge } from '@marketing/components/status-badge';
import type { SubscriptionTarget } from '@marketing/components/subscription-dialog';
import { formatDate, formatDateTime } from '@marketing/lib/format';
import type { ContactStatus, CustomFieldType } from '@marketing/types';

const sources: Record<string, string> = {
    manual: 'added by hand',
    api: 'via the API',
    import: 'imported',
};

export type ContactPreviewData = {
    id: number;
    email: string;
    name: string | null;
    status: ContactStatus;
    source: string;
    tags: string[];
    lists: string[];
    fields: {
        label: string;
        type: CustomFieldType;
        value: string | number | boolean;
    }[];
    unsubscribed_at: string | null;
    archived_at: string | null;
    created_at: string;
    activity: {
        id: number;
        title: string;
        detail: string | null;
        campaign_id: number | null;
        occurred_at: string;
    }[];
};

/**
 * A side panel with one contact's details and latest activity, so a list can be skimmed without leaving it.
 * `contact` is null while it loads, which shows a skeleton.
 */
export function ContactPreview({
    open,
    contact,
    onClose,
    onChangeSubscription,
}: {
    open: boolean;
    contact: ContactPreviewData | null;
    onClose: () => void;
    /** Shows an unsubscribe, or subscribe again, button for people who may change it. */
    onChangeSubscription?: (contact: SubscriptionTarget) => void;
}) {
    return (
        <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
            <SheetContent className="w-full gap-0 sm:max-w-md">
                {contact ? (
                    <>
                        <SheetHeader className="gap-1 border-b p-5 pr-12">
                            <SheetTitle className="truncate text-base">
                                {contact.name ?? contact.email}
                            </SheetTitle>
                            <SheetDescription className="truncate">
                                {contact.name ? contact.email : 'No name'}
                            </SheetDescription>
                            <div className="flex flex-wrap gap-1.5 pt-2">
                                <ContactStatusBadge status={contact.status} />
                                {contact.archived_at && (
                                    <StatusBadge tone="warning">
                                        Archived
                                    </StatusBadge>
                                )}
                            </div>
                        </SheetHeader>

                        <div className="flex-1 space-y-6 overflow-y-auto p-5">
                            <dl className="grid grid-cols-[7rem_1fr] gap-x-4 gap-y-2.5 text-sm">
                                <Detail label="Added">
                                    {formatDate(contact.created_at)} ·{' '}
                                    {sources[contact.source] ?? contact.source}
                                </Detail>
                                {contact.unsubscribed_at && (
                                    <Detail label="Unsubscribed">
                                        {formatDate(contact.unsubscribed_at)}
                                    </Detail>
                                )}
                                <Detail label="Lists">
                                    {contact.lists.length > 0
                                        ? contact.lists.join(', ')
                                        : 'None'}
                                </Detail>
                                <Detail label="Tags">
                                    {contact.tags.length > 0 ? (
                                        <span className="flex flex-wrap gap-1">
                                            {contact.tags.map((tag) => (
                                                <Badge
                                                    key={tag}
                                                    variant="outline"
                                                >
                                                    {tag}
                                                </Badge>
                                            ))}
                                        </span>
                                    ) : (
                                        'None'
                                    )}
                                </Detail>
                                {contact.fields.map((field) => (
                                    <Detail
                                        key={field.label}
                                        label={field.label}
                                    >
                                        {fieldValue(field.type, field.value)}
                                    </Detail>
                                ))}
                            </dl>

                            <section className="space-y-3">
                                <h3 className="text-sm font-semibold">
                                    Recent activity
                                </h3>
                                {contact.activity.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nothing yet.
                                    </p>
                                ) : (
                                    <ol className="space-y-3 border-l pl-4">
                                        {contact.activity.map((entry) => (
                                            <li
                                                key={entry.id}
                                                className="relative text-sm"
                                            >
                                                <span className="absolute top-1.5 -left-[1.3rem] size-2 rounded-full bg-muted-foreground/50" />
                                                {entry.campaign_id ? (
                                                    <Link
                                                        href={showCampaign(
                                                            entry.campaign_id,
                                                        )}
                                                        className="hover:underline"
                                                    >
                                                        {entry.title}
                                                    </Link>
                                                ) : (
                                                    <span>{entry.title}</span>
                                                )}
                                                {entry.detail && (
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {entry.detail}
                                                    </p>
                                                )}
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        entry.occurred_at,
                                                    )}
                                                </p>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </section>
                        </div>

                        <SheetFooter className="flex-row border-t p-4 [&>*]:flex-1">
                            {onChangeSubscription && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        onChangeSubscription({
                                            id: contact.id,
                                            email: contact.email,
                                            name: contact.name,
                                            status: contact.status,
                                        })
                                    }
                                >
                                    {contact.status === 'subscribed' ? (
                                        <>
                                            <UserMinus />
                                            Unsubscribe
                                        </>
                                    ) : (
                                        <>
                                            <UserCheck />
                                            Subscribe again
                                        </>
                                    )}
                                </Button>
                            )}
                            <Button asChild>
                                <Link href={showContact(contact.id)}>
                                    Open full profile
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </SheetFooter>
                    </>
                ) : (
                    <div className="space-y-4 p-5" aria-busy="true">
                        <SheetTitle className="sr-only">
                            Loading contact
                        </SheetTitle>
                        <Skeleton className="h-5 w-40" />
                        <Skeleton className="h-4 w-56" />
                        <Skeleton className="h-28 w-full" />
                        <Skeleton className="h-40 w-full" />
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </>
    );
}

/** A custom field value as people read it: yes or no, a local date, or a grouped number. */
function fieldValue(
    type: CustomFieldType,
    value: string | number | boolean,
): string {
    switch (type) {
        case 'boolean':
            return value ? 'Yes' : 'No';
        case 'date': {
            // A bare 2026-10-07 would be read as UTC midnight, so build it as a local calendar date instead.
            const [year, month, day] = String(value).split('-').map(Number);

            return day
                ? new Date(year, month - 1, day).toLocaleDateString(undefined, {
                      dateStyle: 'medium',
                  })
                : String(value);
        }
        case 'number':
            return Number(value).toLocaleString();
        default:
            return String(value);
    }
}
