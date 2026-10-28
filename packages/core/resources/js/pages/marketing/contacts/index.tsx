import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    create as createContact,
    index as contactsIndex,
    show as showContact,
} from '@/routes/marketing/contacts';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { NativeSelect } from '@marketing/components/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { Pagination } from '@marketing/components/pagination';
import { useCan } from '@marketing/hooks/use-workspace';
import type { ContactStatus, Option, Paginated } from '@marketing/types';

type ContactRow = {
    id: number;
    email: string;
    name: string | null;
    status: ContactStatus;
    tags: string[];
    created_at: string;
};

type Filters = {
    search?: string;
    status?: string;
    list?: string;
    tag?: string;
    segment?: string;
    archived?: string;
};

export default function ContactsIndex({
    contacts,
    filters,
    lists,
    tags,
    segments,
}: {
    contacts: Paginated<ContactRow>;
    filters: Filters;
    lists: Option[];
    tags: Option[];
    segments: Option[];
}) {
    const can = useCan();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (changes: Filters) => {
        const next = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(
                ([, value]) => value !== '' && value !== undefined,
            ),
        );

        router.get(contactsIndex.url(), next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        applyFilters({ search });
    };

    return (
        <>
            <Head title="Contacts" />

            <PageBody>
                <PageHeader
                    title="Contacts"
                    description={`${contacts.total.toLocaleString()} ${filters.archived ? 'archived ' : ''}contacts`}
                    actions={
                        can('contacts.manage') && (
                            <Button asChild>
                                <Link href={createContact()}>
                                    <Plus />
                                    Add contact
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <form onSubmit={submitSearch} className="min-w-56 flex-1">
                        <Input
                            type="search"
                            placeholder="Search by email or name"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </form>
                    <FilterSelect
                        label="Segment"
                        value={filters.segment}
                        options={segments}
                        onChange={(segment) => applyFilters({ segment })}
                    />
                    <FilterSelect
                        label="List"
                        value={filters.list}
                        options={lists}
                        onChange={(list) => applyFilters({ list })}
                    />
                    <FilterSelect
                        label="Tag"
                        value={filters.tag}
                        options={tags}
                        onChange={(tag) => applyFilters({ tag })}
                    />
                    <NativeSelect
                        aria-label="Status"
                        value={filters.status ?? ''}
                        onChange={(event) =>
                            applyFilters({ status: event.target.value })
                        }
                    >
                        <option value="">Any status</option>
                        <option value="subscribed">Subscribed</option>
                        <option value="unsubscribed">Unsubscribed</option>
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Archived"
                        value={filters.archived ? '1' : ''}
                        onChange={(event) =>
                            applyFilters({ archived: event.target.value })
                        }
                    >
                        <option value="">Active</option>
                        <option value="1">Archived</option>
                    </NativeSelect>
                </div>

                <DataTable
                    headers={['Email', 'Name', 'Status', 'Tags', 'Added']}
                    empty={
                        contacts.data.length === 0 && (
                            <EmptyRow message="No contacts match these filters." />
                        )
                    }
                >
                    {contacts.data.map((contact) => (
                        <tr key={contact.id} className="hover:bg-muted/30">
                            <Cell>
                                <Link
                                    href={showContact(contact.id)}
                                    className="font-medium hover:underline"
                                >
                                    {contact.email}
                                </Link>
                            </Cell>
                            <Cell className="text-muted-foreground">
                                {contact.name ?? '—'}
                            </Cell>
                            <Cell>
                                <StatusBadge status={contact.status} />
                            </Cell>
                            <Cell>
                                <div className="flex flex-wrap gap-1">
                                    {contact.tags.map((tag) => (
                                        <Badge key={tag} variant="outline">
                                            {tag}
                                        </Badge>
                                    ))}
                                </div>
                            </Cell>
                            <Cell className="whitespace-nowrap text-muted-foreground">
                                {new Date(
                                    contact.created_at,
                                ).toLocaleDateString()}
                            </Cell>
                        </tr>
                    ))}
                </DataTable>

                <Pagination page={contacts} />
            </PageBody>
        </>
    );
}

function FilterSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value?: string;
    options: Option[];
    onChange: (value: string) => void;
}) {
    if (options.length === 0) {
        return null;
    }

    return (
        <NativeSelect
            aria-label={label}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">Any {label.toLowerCase()}</option>
            {options.map((option) => (
                <option key={option.id} value={option.id}>
                    {option.name}
                </option>
            ))}
        </NativeSelect>
    );
}

export function StatusBadge({ status }: { status: ContactStatus }) {
    return (
        <Badge variant={status === 'subscribed' ? 'secondary' : 'outline'}>
            {status === 'subscribed' ? 'Subscribed' : 'Unsubscribed'}
        </Badge>
    );
}

ContactsIndex.layout = {
    breadcrumbs: [{ title: 'Contacts', href: contactsIndex() }],
};
