import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Ban,
    CircleAlert,
    Clock,
    Ellipsis,
    Mail,
    MailCheck,
    MailOpen,
    MousePointerClick,
    Send,
    UserCheck,
    UserMinus,
    Workflow,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import ContactController from '@/actions/Gerenuk/Marketing/Http/Controllers/ContactController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { show as showCampaign } from '@/routes/marketing/campaigns';
import {
    index as contactsIndex,
    show as showContact,
} from '@/routes/marketing/contacts';
import {
    ContactAvatar,
    contactSources,
    CustomFieldInput,
    ListPicker,
} from '@marketing/components/contact-fields';
import type { CustomFieldValue } from '@marketing/components/contact-fields';
import { PageBody } from '@marketing/components/page-header';
import { SettingsSection } from '@marketing/components/settings-section';
import { StatusBadge } from '@marketing/components/status-badge';
import { SubscriptionDialog } from '@marketing/components/subscription-dialog';
import { TagInput } from '@marketing/components/tag-input';
import {
    useSaveShortcut,
    useUnsavedChangesGuard,
} from '@marketing/hooks/use-editor';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDate, formatDateTime } from '@marketing/lib/format';
import type { ContactStatus, CustomField, Option } from '@marketing/types';
import { Shout } from '@marketing/components/shout';

type Contact = {
    id: number;
    email: string;
    first_name: string | null;
    last_name: string | null;
    status: ContactStatus;
    source: string;
    custom_fields: Record<string, CustomFieldValue>;
    tags: string[];
    lists: number[];
    unsubscribed_at: string | null;
    archived_at: string | null;
    created_at: string;
};

type FormData = {
    email: string;
    first_name: string;
    last_name: string;
    custom_fields: Record<string, CustomFieldValue>;
    tags: string[];
    lists: number[];
};

type TimelineEntry = {
    id: number;
    type: string;
    title: string;
    detail: string | null;
    campaign_id: number | null;
    occurred_at: string;
};

type Engagement = { received: number; opened: number; clicked: number };

const eventIcons: Record<string, LucideIcon> = {
    sent: Send,
    delivered: MailCheck,
    deferred: Clock,
    bounced: CircleAlert,
    dropped: Ban,
    complained: CircleAlert,
    opened: MailOpen,
    clicked: MousePointerClick,
    unsubscribed: UserMinus,
    custom: Zap,
    automation_entered: Workflow,
    automation_completed: Workflow,
    automation_exited: Workflow,
};

/** Events that went wrong, marked in red on the timeline. */
const problemEvents = ['bounced', 'dropped', 'complained'];

export default function ContactShow({
    contact,
    lists,
    tags,
    customFields,
    timeline,
    engagement,
}: {
    contact: Contact;
    statuses: ContactStatus[];
    lists: Option[];
    tags: string[];
    customFields: CustomField[];
    timeline?: TimelineEntry[];
    engagement?: Engagement;
}) {
    const can = useCan();
    const canEdit = can('contacts.manage');

    const form = useForm<FormData>({
        email: contact.email,
        first_name: contact.first_name ?? '',
        last_name: contact.last_name ?? '',
        custom_fields: contact.custom_fields,
        tags: contact.tags,
        lists: contact.lists,
    });

    const errors = form.errors as Record<string, string | undefined>;
    const name =
        [contact.first_name, contact.last_name].filter(Boolean).join(' ') ||
        null;

    setLayoutProps({
        breadcrumbs: [
            { title: 'Contacts', href: contactsIndex() },
            { title: name ?? contact.email, href: showContact(contact.id) },
        ],
    });

    const [changingSubscription, setChangingSubscription] = useState(false);

    // Subscription changes straight away from its own button, so saving sends whatever it is now.
    const save = () => {
        form.transform((data) => ({ ...data, status: contact.status }));
        const sent = structuredClone(form.data);

        form.submit(ContactController.update(contact.id), {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(sent),
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        save();
    };

    useSaveShortcut(save, canEdit && form.isDirty);
    useUnsavedChangesGuard(canEdit && form.isDirty && !form.processing);

    return (
        <>
            <Head title={name ?? contact.email} />

            <PageBody>
                <header className="flex flex-wrap items-center gap-4">
                    <ContactAvatar
                        name={name}
                        email={contact.email}
                        className="size-14 text-lg"
                    />
                    <div className="min-w-0 flex-1 space-y-1.5">
                        <h1 className="truncate text-2xl font-semibold">
                            {name ?? contact.email}
                        </h1>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                            {name && (
                                <span className="flex min-w-0 items-center gap-1.5">
                                    <Mail className="size-3.5 shrink-0" />
                                    <span className="truncate">
                                        {contact.email}
                                    </span>
                                </span>
                            )}
                            <StatusBadge
                                tone={
                                    contact.status === 'subscribed'
                                        ? 'success'
                                        : 'neutral'
                                }
                            >
                                {contact.status === 'subscribed'
                                    ? 'Subscribed'
                                    : 'Unsubscribed'}
                            </StatusBadge>
                            {contact.unsubscribed_at &&
                                contact.status === 'unsubscribed' && (
                                    <span>
                                        since{' '}
                                        {formatDate(contact.unsubscribed_at)}
                                    </span>
                                )}
                            <span>
                                Added {formatDate(contact.created_at)}{' '}
                                {contactSources[contact.source] ??
                                    contact.source}
                            </span>
                        </div>
                    </div>
                    {canEdit && (
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setChangingSubscription(true)}
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
                            {!contact.archived_at && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            aria-label="More actions"
                                        >
                                            <Ellipsis />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                router.post(
                                                    ContactController.archive.url(
                                                        contact.id,
                                                    ),
                                                )
                                            }
                                        >
                                            <Archive />
                                            Archive contact
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </div>
                    )}
                </header>

                {contact.archived_at && (
                    <Shout
                        type="warning"
                        icon={Archive}
                        heading={`Archived ${formatDate(contact.archived_at)}`}
                        actions={
                            canEdit && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="bg-card"
                                    onClick={() =>
                                        router.post(
                                            ContactController.restore.url(
                                                contact.id,
                                            ),
                                        )
                                    }
                                >
                                    <ArchiveRestore />
                                    Restore
                                </Button>
                            )
                        }
                    >
                        Archived contacts are hidden from your lists and never
                        emailed.
                    </Shout>
                )}

                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <form onSubmit={submit} className="min-w-0 space-y-6">
                        <fieldset disabled={!canEdit} className="space-y-6">
                            <SettingsSection title="Profile">
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        required
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData(
                                                'email',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid content-start gap-2">
                                        <Label htmlFor="first_name">
                                            First name
                                        </Label>
                                        <Input
                                            id="first_name"
                                            value={form.data.first_name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'first_name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={errors.first_name}
                                        />
                                    </div>
                                    <div className="grid content-start gap-2">
                                        <Label htmlFor="last_name">
                                            Last name
                                        </Label>
                                        <Input
                                            id="last_name"
                                            value={form.data.last_name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'last_name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={errors.last_name}
                                        />
                                    </div>
                                </div>
                            </SettingsSection>

                            <SettingsSection
                                title="Lists and tags"
                                description="Lists are the audiences campaigns go to. Tags are labels for segments and automations."
                            >
                                <div className="grid content-start gap-2">
                                    <Label>Lists</Label>
                                    {lists.length > 0 ? (
                                        <ListPicker
                                            lists={lists}
                                            value={form.data.lists}
                                            onChange={(value) =>
                                                form.setData('lists', value)
                                            }
                                        />
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            This workspace has no lists yet.
                                        </p>
                                    )}
                                    <InputError message={errors.lists} />
                                </div>
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="tags">Tags</Label>
                                    <TagInput
                                        id="tags"
                                        value={form.data.tags}
                                        onChange={(value) =>
                                            form.setData('tags', value)
                                        }
                                        suggestions={tags}
                                    />
                                    <InputError message={errors.tags} />
                                </div>
                            </SettingsSection>

                            {customFields.length > 0 && (
                                <SettingsSection
                                    title="Custom fields"
                                    description="Use them in segments, and to personalise emails."
                                >
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {customFields.map((field) => (
                                            <CustomFieldInput
                                                key={field.key}
                                                field={field}
                                                value={
                                                    form.data.custom_fields[
                                                        field.key
                                                    ] ?? null
                                                }
                                                onChange={(value) =>
                                                    form.setData(
                                                        'custom_fields',
                                                        {
                                                            ...form.data
                                                                .custom_fields,
                                                            [field.key]: value,
                                                        },
                                                    )
                                                }
                                                error={
                                                    errors[
                                                        `custom_fields.${field.key}`
                                                    ]
                                                }
                                            />
                                        ))}
                                    </div>
                                    <InputError
                                        message={errors.custom_fields}
                                    />
                                </SettingsSection>
                            )}
                        </fieldset>

                        {canEdit && (form.isDirty || form.processing) && (
                            <div className="sticky bottom-4 z-10 flex items-center gap-3 rounded-xl border bg-card px-4 py-2.5 shadow-lg">
                                <span className="size-1.5 rounded-full bg-amber-500" />
                                <p className="flex-1 text-sm">
                                    Unsaved changes
                                </p>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={form.processing}
                                    onClick={() => {
                                        form.reset();
                                        form.clearErrors();
                                    }}
                                >
                                    Discard
                                </Button>
                                <Button size="sm" disabled={form.processing}>
                                    {form.processing && <Spinner />}
                                    Save changes
                                </Button>
                            </div>
                        )}
                    </form>

                    <aside className="space-y-6">
                        <EngagementCard engagement={engagement} />
                        <ActivityCard timeline={timeline} />
                    </aside>
                </div>
            </PageBody>

            <SubscriptionDialog
                contact={
                    changingSubscription
                        ? {
                              id: contact.id,
                              email: contact.email,
                              name,
                              status: contact.status,
                          }
                        : null
                }
                onClose={() => setChangingSubscription(false)}
            />
        </>
    );
}

function EngagementCard({ engagement }: { engagement?: Engagement }) {
    const rate = (count: number) =>
        engagement && engagement.received > 0
            ? `${Math.round((count / engagement.received) * 100)}%`
            : null;

    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <h2 className="border-b px-5 py-3.5 text-sm font-semibold">
                Engagement
            </h2>
            <dl className="grid grid-cols-3 divide-x">
                <Stat label="Received" value={engagement?.received} />
                <Stat
                    label="Opened"
                    value={engagement?.opened}
                    rate={engagement && rate(engagement.opened)}
                />
                <Stat
                    label="Clicked"
                    value={engagement?.clicked}
                    rate={engagement && rate(engagement.clicked)}
                />
            </dl>
        </section>
    );
}

function Stat({
    label,
    value,
    rate,
}: {
    label: string;
    value?: number;
    rate?: string | null;
}) {
    return (
        <div className="space-y-1 px-4 py-3.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="flex items-baseline gap-1.5">
                {value === undefined ? (
                    <Skeleton className="h-6 w-8" />
                ) : (
                    <span className="text-xl font-semibold tabular-nums">
                        {value.toLocaleString()}
                    </span>
                )}
                {rate && (
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {rate}
                    </span>
                )}
            </dd>
        </div>
    );
}

function ActivityCard({ timeline }: { timeline?: TimelineEntry[] }) {
    let content: ReactNode;

    if (timeline === undefined) {
        content = (
            <div className="space-y-4 p-5">
                {[0, 1, 2].map((index) => (
                    <div key={index} className="flex gap-3">
                        <Skeleton className="size-7 rounded-full" />
                        <div className="flex-1 space-y-1.5">
                            <Skeleton className="h-4 w-3/4" />
                            <Skeleton className="h-3 w-1/3" />
                        </div>
                    </div>
                ))}
            </div>
        );
    } else if (timeline.length === 0) {
        content = (
            <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                No activity yet. Sends, opens and clicks show up here.
            </p>
        );
    } else {
        content = (
            <ol className="p-5">
                {timeline.map((entry, index) => {
                    const Icon = eventIcons[entry.type] ?? Zap;
                    const problem = problemEvents.includes(entry.type);

                    return (
                        <li
                            key={entry.id}
                            className="relative flex gap-3 pb-5 last:pb-0"
                        >
                            {index < timeline.length - 1 && (
                                <span
                                    aria-hidden
                                    className="absolute top-7 bottom-0 left-3.5 w-px bg-border"
                                />
                            )}
                            <span
                                className={cn(
                                    'flex size-7 shrink-0 items-center justify-center rounded-full border bg-card text-muted-foreground',
                                    problem &&
                                        'border-red-200 bg-red-50 text-red-600 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-400',
                                )}
                            >
                                <Icon className="size-3.5" />
                            </span>
                            <div className="min-w-0 flex-1 pt-0.5 text-sm">
                                {entry.campaign_id ? (
                                    <Link
                                        href={showCampaign(entry.campaign_id)}
                                        className="hover:underline"
                                    >
                                        {entry.title}
                                    </Link>
                                ) : (
                                    <span>{entry.title}</span>
                                )}
                                {entry.detail && (
                                    <p
                                        className="truncate text-xs text-muted-foreground"
                                        title={entry.detail}
                                    >
                                        {entry.detail}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    {formatDateTime(entry.occurred_at)}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ol>
        );
    }

    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <h2 className="border-b px-5 py-3.5 text-sm font-semibold">
                Activity
            </h2>
            {content}
        </section>
    );
}

ContactShow.layout = {
    breadcrumbs: [{ title: 'Contacts', href: contactsIndex() }],
};
