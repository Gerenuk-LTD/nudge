import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Ban,
    CircleAlert,
    Download,
    Ellipsis,
    MailX,
    ShieldCheck,
    User,
    UserRoundX,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import SuppressionController from '@/actions/Gerenuk/Marketing/Http/Controllers/SuppressionController';
import InputError from '@/components/input-error';
import { ConfirmDialog } from '@marketing/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { show as showContact } from '@/routes/marketing/contacts';
import { index as suppressionsIndex } from '@/routes/marketing/suppressions';
import { EmptyState } from '@marketing/components/empty-state';
import { byId, DataTable } from '@marketing/components/data-table';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { Pagination } from '@marketing/components/pagination';
import { StatusBadge } from '@marketing/components/status-badge';
import { ResetFilters, TableSearch } from '@marketing/components/table-toolbar';
import { useTableQuery } from '@marketing/hooks/use-table-query';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDate } from '@marketing/lib/format';
import type { Paginated } from '@marketing/types';
import { Shout } from '@marketing/components/shout';

type Reason = 'bounce' | 'complaint' | 'manual';

type Suppression = {
    id: number;
    email: string;
    reason: Reason;
    detail: string | null;
    created_at: string | null;
    contact: { id: number; name: string | null } | null;
};

const reasons: Record<
    Reason,
    { label: string; card: string; hint: string; icon: LucideIcon }
> = {
    bounce: {
        label: 'Hard bounce',
        card: 'Hard bounces',
        hint: 'The address does not exist or rejects mail',
        icon: MailX,
    },
    complaint: {
        label: 'Spam complaint',
        card: 'Spam complaints',
        hint: 'They marked one of your emails as spam',
        icon: CircleAlert,
    },
    manual: {
        label: 'Blocked by you',
        card: 'Blocked by you',
        hint: 'Added here, or by an import or the API',
        icon: Ban,
    },
};

export default function SuppressionsIndex({
    suppressions,
    counts,
}: {
    suppressions: Paginated<Suppression>;
    counts: Partial<Record<Reason, number>>;
}) {
    const canEdit = useCan()('contacts.manage');
    const table = useTableQuery();
    const [blocking, setBlocking] = useState(false);
    const [removing, setRemoving] = useState<Suppression | null>(null);
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
    const selected = table.get('reason');

    const columns: ColumnDef<Suppression>[] = [
        {
            id: 'email',
            header: 'Email',
            meta: { sortable: true },
            cell: ({ row: { original: suppression } }) => (
                <div className="grid leading-tight">
                    <span className="font-medium">{suppression.email}</span>
                    {suppression.contact ? (
                        <Link
                            href={showContact(suppression.contact.id)}
                            className="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground hover:underline"
                        >
                            <User className="size-3" />
                            {suppression.contact.name ?? 'Contact'}
                        </Link>
                    ) : (
                        <span className="text-xs text-muted-foreground">
                            Not a contact
                        </span>
                    )}
                </div>
            ),
        },
        {
            id: 'reason',
            header: 'Reason',
            meta: { sortable: true },
            cell: ({ row: { original: suppression } }) => (
                <div className="space-y-1">
                    <StatusBadge
                        tone={
                            suppression.reason === 'manual'
                                ? 'neutral'
                                : 'danger'
                        }
                    >
                        {reasons[suppression.reason]?.label ??
                            suppression.reason}
                    </StatusBadge>
                    {suppression.detail && (
                        <p
                            className="max-w-md truncate text-xs text-muted-foreground"
                            title={suppression.detail}
                        >
                            {suppression.detail}
                        </p>
                    )}
                </div>
            ),
        },
        {
            id: 'since',
            header: 'Since',
            meta: {
                className: 'whitespace-nowrap text-muted-foreground',
                sortable: true,
            },
            cell: ({ row }) => formatDate(row.original.created_at),
        },
        ...(canEdit
            ? [
                  {
                      id: 'actions',
                      header: () => <span className="sr-only">Actions</span>,
                      meta: { className: 'w-px' },
                      cell: ({ row: { original: suppression } }) => (
                          <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                  <Button
                                      variant="ghost"
                                      size="icon"
                                      className="size-8 text-muted-foreground"
                                      aria-label={`Actions for ${suppression.email}`}
                                  >
                                      <Ellipsis />
                                  </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                  {suppression.contact && (
                                      <>
                                          <DropdownMenuItem asChild>
                                              <Link
                                                  href={showContact(
                                                      suppression.contact.id,
                                                  )}
                                              >
                                                  <User />
                                                  View contact
                                              </Link>
                                          </DropdownMenuItem>
                                          <DropdownMenuSeparator />
                                      </>
                                  )}
                                  <DropdownMenuItem
                                      variant="destructive"
                                      onSelect={() => setRemoving(suppression)}
                                  >
                                      <UserRoundX />
                                      Allow emails again
                                  </DropdownMenuItem>
                              </DropdownMenuContent>
                          </DropdownMenu>
                      ),
                  } satisfies ColumnDef<Suppression>,
              ]
            : []),
    ];

    return (
        <>
            <Head title="Suppressions" />

            <PageBody>
                <PageHeader
                    title="Suppressions"
                    description="Addresses that are never emailed, whatever list or segment they are in."
                    actions={
                        <>
                            {total > 0 && (
                                <Button variant="outline" asChild>
                                    <a
                                        href={SuppressionController.export.url()}
                                    >
                                        <Download />
                                        Export
                                    </a>
                                </Button>
                            )}
                            {canEdit && (
                                <Button onClick={() => setBlocking(true)}>
                                    <Ban />
                                    Block addresses
                                </Button>
                            )}
                        </>
                    }
                />

                {total === 0 ? (
                    <EmptyState
                        icon={ShieldCheck}
                        title="No suppressed addresses"
                        description="Addresses that hard bounce or mark you as spam are added here automatically, so you never email them again. You can also block addresses yourself."
                        actions={
                            canEdit && (
                                <Button
                                    size="sm"
                                    className="mt-1"
                                    onClick={() => setBlocking(true)}
                                >
                                    <Ban />
                                    Block addresses
                                </Button>
                            )
                        }
                    />
                ) : (
                    <>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {(Object.keys(reasons) as Reason[]).map(
                                (reason) => {
                                    const {
                                        card,
                                        hint,
                                        icon: Icon,
                                    } = reasons[reason];
                                    const active = selected === reason;

                                    return (
                                        <button
                                            key={reason}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() =>
                                                table.update({
                                                    reason: active
                                                        ? null
                                                        : reason,
                                                })
                                            }
                                            className={cn(
                                                'rounded-xl border bg-card p-4 text-left shadow-xs transition-colors outline-none hover:border-foreground/20 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                                active &&
                                                    'border-foreground ring-1 ring-foreground hover:border-foreground',
                                            )}
                                        >
                                            <span className="flex items-center gap-2 text-sm text-muted-foreground">
                                                <Icon
                                                    className={cn(
                                                        'size-4',
                                                        reason !== 'manual' &&
                                                            'text-red-600 dark:text-red-400',
                                                    )}
                                                />
                                                {card}
                                            </span>
                                            <span className="mt-1 block text-2xl font-semibold tabular-nums">
                                                {(
                                                    counts[reason] ?? 0
                                                ).toLocaleString()}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {hint}
                                            </span>
                                        </button>
                                    );
                                },
                            )}
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <TableSearch
                                value={table.get('search')}
                                onSearch={(search) => table.update({ search })}
                                placeholder="Search by email"
                            />
                            <ResetFilters
                                active={selected !== ''}
                                onReset={() => table.update({ reason: null })}
                            />
                        </div>

                        <DataTable
                            columns={columns}
                            data={suppressions.data}
                            getRowId={byId}
                            emptyMessage="No suppressed addresses match."
                            sort={table.sort}
                        />

                        <Pagination page={suppressions} query={table} />
                    </>
                )}
            </PageBody>

            {canEdit && (
                <>
                    <Dialog open={blocking} onOpenChange={setBlocking}>
                        <DialogContent>
                            {blocking && (
                                <BlockForm onClose={() => setBlocking(false)} />
                            )}
                        </DialogContent>
                    </Dialog>
                    <RemoveConfirm
                        suppression={removing}
                        onClose={() => setRemoving(null)}
                    />
                </>
            )}
        </>
    );
}

function BlockForm({ onClose }: { onClose: () => void }) {
    const form = useForm({ emails: '', note: '' });
    const count = form.data.emails
        .split(/[\s,;]+/)
        .filter((email) => email.includes('@')).length;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(SuppressionController.store(), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="space-y-1.5">
                <DialogTitle>Block addresses</DialogTitle>
                <DialogDescription>
                    They are never emailed, even if they are on a list or
                    subscribe again. Contacts with these addresses are kept.
                </DialogDescription>
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="block-emails">Email addresses</Label>
                <Textarea
                    id="block-emails"
                    autoFocus
                    rows={5}
                    placeholder={'jane@example.com\nsomeone@example.org'}
                    className="font-mono text-13"
                    value={form.data.emails}
                    onChange={(event) =>
                        form.setData('emails', event.target.value)
                    }
                />
                <p className="text-xs text-muted-foreground">
                    One per line, or separated by commas. Up to 1,000 at once.
                </p>
                <InputError message={form.errors.emails} />
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="block-note">
                    Note{' '}
                    <span className="font-normal text-muted-foreground">
                        (optional)
                    </span>
                </Label>
                <Input
                    id="block-note"
                    placeholder="Asked to be removed by phone"
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
                <InputError message={form.errors.note} />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button disabled={form.processing || count === 0}>
                    {form.processing && <Spinner />}
                    {count > 1
                        ? `Block ${count.toLocaleString()} addresses`
                        : 'Block address'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function RemoveConfirm({
    suppression,
    onClose,
}: {
    suppression: Suppression | null;
    onClose: () => void;
}) {
    const risky = suppression !== null && suppression.reason !== 'manual';

    return (
        <ConfirmDialog
            open={suppression !== null}
            onOpenChange={(open) => !open && onClose()}
            title={suppression && <>Email {suppression.email} again?</>}
            description="It is taken off the suppression list, so campaigns and automations can reach it again."
            confirmLabel="Allow emails again"
            destructive={risky}
            onConfirm={() =>
                suppression &&
                router.delete(
                    SuppressionController.destroy.url(suppression.id),
                    {
                        preserveScroll: true,
                        onSuccess: onClose,
                    },
                )
            }
        >
            {suppression && risky && (
                <Shout
                    type="warning"
                    heading={
                        suppression.reason === 'bounce'
                            ? 'This address bounced'
                            : 'This person marked you as spam'
                    }
                >
                    Sending to it again can hurt your sender reputation and land
                    your emails in spam for everyone. Only do this if you know
                    the problem is fixed.
                </Shout>
            )}
        </ConfirmDialog>
    );
}

SuppressionsIndex.layout = {
    breadcrumbs: [{ title: 'Suppressions', href: suppressionsIndex() }],
};
