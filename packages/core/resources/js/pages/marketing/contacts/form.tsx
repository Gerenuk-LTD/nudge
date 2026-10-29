import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ContactController from '@/actions/Gerenuk/Marketing/Http/Controllers/ContactController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { SettingsSection } from '@marketing/components/settings-section';
import { show as showCampaign } from '@/routes/marketing/campaigns';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import { NativeSelect } from '@/components/ui/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { TagInput } from '@marketing/components/tag-input';
import { useCan } from '@marketing/hooks/use-workspace';
import type { ContactStatus, CustomField, Option } from '@marketing/types';
import { formatDate, formatDateTime } from '@marketing/lib/format';

type Contact = {
    id: number;
    email: string;
    first_name: string | null;
    last_name: string | null;
    status: ContactStatus;
    source: string;
    custom_fields: Record<string, string | number | boolean | null>;
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
    status: ContactStatus;
    custom_fields: Record<string, string | number | boolean | null>;
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

export default function ContactForm({
    contact,
    lists,
    tags,
    customFields,
    timeline,
}: {
    timeline?: TimelineEntry[];
    contact: Contact | null;
    statuses: ContactStatus[];
    lists: Option[];
    tags: string[];
    customFields: CustomField[];
}) {
    const can = useCan();
    const canEdit = can('contacts.manage');

    const form = useForm<FormData>({
        email: contact?.email ?? '',
        first_name: contact?.first_name ?? '',
        last_name: contact?.last_name ?? '',
        status: contact?.status ?? 'subscribed',
        custom_fields: contact?.custom_fields ?? {},
        tags: contact?.tags ?? [],
        lists: contact?.lists ?? [],
    });

    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (contact) {
            form.submit(ContactController.update(contact.id), {
                preserveScroll: true,
            });
        } else {
            form.submit(ContactController.store());
        }
    };

    const setCustomField = (
        key: string,
        value: string | number | boolean | null,
    ) =>
        form.setData('custom_fields', {
            ...form.data.custom_fields,
            [key]: value,
        });

    const toggleList = (id: number, checked: boolean) =>
        form.setData(
            'lists',
            checked
                ? [...form.data.lists, id]
                : form.data.lists.filter((listId) => listId !== id),
        );

    const title = contact ? contact.email : 'Add contact';

    return (
        <>
            <Head title={title} />

            <PageBody>
                <PageHeader
                    title={title}
                    description={
                        contact
                            ? `Added ${formatDate(contact.created_at)} via ${contact.source}${contact.archived_at ? ' · Archived' : ''}`
                            : 'Add a single contact. Use an import for many at once.'
                    }
                    actions={
                        contact &&
                        canEdit &&
                        (contact.archived_at ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        ContactController.restore.url(
                                            contact.id,
                                        ),
                                    )
                                }
                            >
                                Restore
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        ContactController.archive.url(
                                            contact.id,
                                        ),
                                    )
                                }
                            >
                                Archive
                            </Button>
                        ))
                    }
                />

                <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,44rem)_minmax(18rem,1fr)]">
                    <form onSubmit={submit} className="space-y-6">
                        <fieldset disabled={!canEdit} className="space-y-6">
                            <SettingsSection
                                title="Details"
                                description="Who this is and whether they get your campaigns."
                            >
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
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="status">
                                        Subscription status
                                    </Label>
                                    <NativeSelect
                                        id="status"
                                        value={form.data.status}
                                        onChange={(event) =>
                                            form.setData(
                                                'status',
                                                event.target
                                                    .value as ContactStatus,
                                            )
                                        }
                                    >
                                        <option value="subscribed">
                                            Subscribed
                                        </option>
                                        <option value="unsubscribed">
                                            Unsubscribed
                                        </option>
                                    </NativeSelect>
                                    {contact?.unsubscribed_at && (
                                        <p className="text-xs text-muted-foreground">
                                            Unsubscribed{' '}
                                            {formatDateTime(
                                                contact.unsubscribed_at,
                                            )}
                                        </p>
                                    )}
                                    <InputError message={errors.status} />
                                </div>
                            </SettingsSection>

                            {customFields.length > 0 && (
                                <SettingsSection
                                    title="Custom fields"
                                    description="Workspace-wide fields you can use in segments and personalise emails with."
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
                                                    setCustomField(
                                                        field.key,
                                                        value,
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

                            <SettingsSection
                                title="Tags and lists"
                                description="Tags are free-form labels. Lists are the audiences campaigns go to."
                            >
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
                                {lists.length > 0 && (
                                    <div className="grid content-start gap-2">
                                        <Label>Lists</Label>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {lists.map((list) => (
                                                <label
                                                    key={list.id}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <Checkbox
                                                        checked={form.data.lists.includes(
                                                            list.id,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            toggleList(
                                                                list.id,
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    {list.name}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </SettingsSection>
                        </fieldset>

                        {canEdit && (
                            <div className="flex justify-end gap-2">
                                <Button variant="ghost" asChild>
                                    <Link href={contactsIndex()}>Cancel</Link>
                                </Button>
                                <Button disabled={form.processing}>
                                    {form.processing && <Spinner />}
                                    {contact ? 'Save changes' : 'Add contact'}
                                </Button>
                            </div>
                        )}
                    </form>

                    {contact && (
                        <SettingsSection
                            title="Activity"
                            description="Sends, opens, clicks and changes, newest first."
                        >
                            {timeline === undefined ? (
                                <Spinner />
                            ) : timeline.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing yet.
                                </p>
                            ) : (
                                <ol className="space-y-3 border-l pl-4">
                                    {timeline.map((entry) => (
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
                        </SettingsSection>
                    )}
                </div>
            </PageBody>
        </>
    );
}

function CustomFieldInput({
    field,
    value,
    onChange,
    error,
}: {
    field: CustomField;
    value: string | number | boolean | null;
    onChange: (value: string | number | boolean | null) => void;
    error?: string;
}) {
    const id = `custom-${field.key}`;

    if (field.type === 'boolean') {
        return (
            <div className="grid content-start gap-2">
                <Label htmlFor={id}>{field.label}</Label>
                <NativeSelect
                    id={id}
                    value={value === null ? '' : value ? '1' : '0'}
                    onChange={(event) =>
                        onChange(
                            event.target.value === ''
                                ? null
                                : event.target.value === '1',
                        )
                    }
                >
                    <option value="">Not set</option>
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </NativeSelect>
                <InputError message={error} />
            </div>
        );
    }

    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{field.label}</Label>
            <Input
                id={id}
                type={
                    field.type === 'number'
                        ? 'number'
                        : field.type === 'date'
                          ? 'date'
                          : 'text'
                }
                step={field.type === 'number' ? 'any' : undefined}
                value={value === null ? '' : String(value)}
                onChange={(event) =>
                    onChange(
                        event.target.value === '' ? null : event.target.value,
                    )
                }
            />
            <InputError message={error} />
        </div>
    );
}

ContactForm.layout = {
    breadcrumbs: [{ title: 'Contacts', href: contactsIndex() }],
};
