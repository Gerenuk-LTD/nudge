import type { ColumnDef } from '@tanstack/react-table';
import {
    Check,
    MailCheck,
    MailOpen,
    MailX,
    MousePointerClick,
    ShieldAlert,
    Trash2,
    UserMinus,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Head, Link, router, usePoll } from '@inertiajs/react';
import CampaignController from '@/actions/Gerenuk/Nudge/Http/Controllers/CampaignController';
import { Badge } from '@/components/ui/badge';
import { ConfirmDialog } from '@nudge/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show as showContact } from '@/routes/nudge/contacts';
import { index as campaignsIndex } from '@/routes/nudge/campaigns';
import { CampaignStatusBadge } from '@nudge/components/campaign-status-badge';
import {
    byId,
    DataTable,
    DataTableSkeleton,
} from '@nudge/components/data-table';
import { PageBody, PageHeader } from '@nudge/components/page-header';
import { SaveTemplateDialog } from '@nudge/components/template-picker';
import { StatTile } from '@nudge/components/stat-tile';
import { StatusBadge } from '@nudge/components/status-badge';
import type { StatusTone } from '@nudge/components/status-badge';
import { Pagination } from '@nudge/components/pagination';
import {
    FacetedFilter,
    ResetFilters,
    TableSearch,
} from '@nudge/components/table-toolbar';
import { useTableQuery } from '@nudge/hooks/use-table-query';
import { useCan } from '@nudge/hooks/use-workspace';
import { formatDateTime, percent } from '@nudge/lib/format';
import type { CampaignSummary, Paginated } from '@nudge/types';

type VariantResult = {
    sent: number;
    opened: number;
    clicked: number;
    rate: number;
};

type AbTest = {
    metric: 'clicks' | 'opens';
    winner: 'a' | 'b' | null;
    decides_at: string | null;
    results: { a: VariantResult; b: VariantResult };
} | null;

type Snapshot = {
    subject: string;
    subject_b: string | null;
    preheader: string | null;
    from_name: string;
    from_email: string;
    reply_to: string | null;
    html: string;
} | null;

type LinkRow = {
    id: number;
    url: string;
    clicks_count: number;
    unique_clicks_count: number;
};

type Recipient = {
    id: number;
    contact_id: number;
    email: string;
    status: string;
    error: string | null;
    opened: boolean;
    clicked: boolean;
};

type AbRow = VariantResult & {
    variant: 'a' | 'b';
    subject: string | null | undefined;
    won: boolean;
};

/** A tick for yes in a table, with the word kept for screen readers; blank means no. */
function YesMark() {
    return (
        <>
            <Check
                className="size-4 text-emerald-600 dark:text-emerald-500"
                aria-hidden="true"
            />
            <span className="sr-only">Yes</span>
        </>
    );
}

const RECIPIENT_TONES: Record<string, StatusTone> = {
    queued: 'neutral',
    held: 'neutral',
    sending: 'info',
    sent: 'info',
    delivered: 'success',
    bounced: 'danger',
    complained: 'danger',
    failed: 'danger',
    skipped: 'warning',
};

const RECIPIENT_STATUSES = [
    'queued',
    'held',
    'sent',
    'delivered',
    'bounced',
    'complained',
    'failed',
    'skipped',
].map((status) => ({
    value: status,
    label: status.charAt(0).toUpperCase() + status.slice(1),
}));

export default function ShowCampaign({
    campaign,
    snapshot,
    links,
    recipients,
    canViewRecipients,
    abTest,
}: {
    canViewRecipients: boolean;
    abTest: AbTest;
    campaign: CampaignSummary;
    snapshot: Snapshot;
    links: LinkRow[];
    recipients?: Paginated<Recipient>;
}) {
    const can = useCan();
    const recipientQuery = useTableQuery({ only: ['recipients'] });
    const { stats } = campaign;
    const active =
        campaign.status === 'sending' || campaign.status === 'scheduled';

    const { start, stop } = usePoll(
        4000,
        { only: ['campaign', 'links'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }
    }, [active, start, stop]);

    const [confirmingStop, setConfirmingStop] = useState(false);
    const [confirmingResend, setConfirmingResend] = useState(false);
    const [confirmingWinner, setConfirmingWinner] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const abRows: AbRow[] = abTest
        ? (['a', 'b'] as const).map((variant) => ({
              ...abTest.results[variant],
              variant,
              subject:
                  variant === 'b'
                      ? (snapshot?.subject_b ?? snapshot?.subject)
                      : snapshot?.subject,
              won: abTest.winner === variant,
          }))
        : [];

    const abColumns: ColumnDef<AbRow>[] = [
        {
            id: 'variant',
            header: 'Variant',
            meta: { className: 'font-medium' },
            cell: ({ row }) => (
                <>
                    {row.original.variant.toUpperCase()}{' '}
                    {row.original.won && (
                        <Badge variant="secondary">Winner</Badge>
                    )}
                </>
            ),
        },
        {
            id: 'subject',
            header: 'Subject',
            meta: { className: 'max-w-sm truncate whitespace-nowrap' },
            cell: ({ row }) => row.original.subject,
        },
        {
            id: 'sent',
            header: 'Sent',
            meta: { numeric: true },
            cell: ({ row }) => row.original.sent.toLocaleString(),
        },
        {
            id: 'opened',
            header: 'Opened',
            meta: { numeric: true },
            cell: ({ row }) => percent(row.original.opened, row.original.sent),
        },
        {
            id: 'clicked',
            header: 'Clicked',
            meta: { numeric: true },
            cell: ({ row }) => percent(row.original.clicked, row.original.sent),
        },
    ];

    const recipientColumns: ColumnDef<Recipient>[] = [
        {
            id: 'email',
            header: 'Email',
            meta: { sortable: true },
            cell: ({ row: { original: recipient } }) => (
                <>
                    <Link
                        href={showContact(recipient.contact_id)}
                        className="hover:underline"
                    >
                        {recipient.email}
                    </Link>
                    {recipient.error && (
                        <p className="text-xs text-red-600 dark:text-red-400">
                            {recipient.error}
                        </p>
                    )}
                </>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            meta: { sortable: true },
            cell: ({ row }) => (
                <StatusBadge
                    tone={RECIPIENT_TONES[row.original.status] ?? 'neutral'}
                    live={row.original.status === 'sending'}
                >
                    {row.original.status.charAt(0).toUpperCase() +
                        row.original.status.slice(1)}
                </StatusBadge>
            ),
        },
        {
            id: 'opened',
            header: 'Opened',
            meta: { sortable: true },
            cell: ({ row }) => row.original.opened && <YesMark />,
        },
        {
            id: 'clicked',
            header: 'Clicked',
            meta: { sortable: true },
            cell: ({ row }) => row.original.clicked && <YesMark />,
        },
    ];

    return (
        <>
            <Head title={campaign.name} />

            <PageBody>
                <PageHeader
                    title={campaign.name}
                    description={
                        campaign.status === 'scheduled'
                            ? `Scheduled for ${formatDateTime(campaign.scheduled_at)}`
                            : campaign.sent_at
                              ? `Sent ${formatDateTime(campaign.sent_at)}`
                              : undefined
                    }
                    meta={<CampaignStatusBadge status={campaign.status} />}
                    actions={
                        <>
                            {can('campaigns.manage') &&
                                campaign.kind === 'broadcast' &&
                                campaign.status === 'cancelled' && (
                                    <Button
                                        variant="destructive-ghost"
                                        onClick={() =>
                                            setConfirmingDelete(true)
                                        }
                                    >
                                        <Trash2 />
                                        Delete
                                    </Button>
                                )}
                            {can('campaigns.manage') &&
                                campaign.kind === 'broadcast' && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                CampaignController.duplicate.url(
                                                    campaign.id,
                                                ),
                                            )
                                        }
                                    >
                                        Duplicate
                                    </Button>
                                )}
                            {can('campaigns.manage') &&
                                campaign.kind === 'broadcast' &&
                                campaign.status === 'sent' && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setConfirmingResend(true)
                                        }
                                    >
                                        Resend to non-openers
                                    </Button>
                                )}
                            {can('campaigns.manage') &&
                                campaign.kind === 'broadcast' && (
                                    <SaveTemplateDialog
                                        size="default"
                                        defaultName={campaign.name}
                                        campaignId={campaign.id}
                                    />
                                )}
                            {can('campaigns.send') &&
                                campaign.status === 'scheduled' && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                CampaignController.unschedule.url(
                                                    campaign.id,
                                                ),
                                            )
                                        }
                                    >
                                        Unschedule
                                    </Button>
                                )}
                            {can('campaigns.send') &&
                                campaign.status === 'sending' && (
                                    <Button
                                        variant="destructive"
                                        onClick={() => setConfirmingStop(true)}
                                    >
                                        Stop sending
                                    </Button>
                                )}
                        </>
                    }
                />

                {campaign.status === 'sending' && (
                    <p className="flex items-center gap-2 text-sm">
                        <Spinner /> Sent {stats.sent.toLocaleString()} of{' '}
                        {stats.recipients.toLocaleString()}
                    </p>
                )}

                <dl className="grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-7">
                    <StatTile
                        icon={Users}
                        label="Recipients"
                        value={stats.recipients.toLocaleString()}
                    />
                    <StatTile
                        icon={MailCheck}
                        label="Delivered"
                        value={percent(stats.delivered, stats.sent)}
                        hint={stats.delivered.toLocaleString()}
                    />
                    <StatTile
                        icon={MousePointerClick}
                        label="Clicked"
                        value={
                            campaign.track_clicks
                                ? percent(stats.clicked, stats.sent)
                                : '—'
                        }
                        hint={
                            campaign.track_clicks
                                ? stats.clicked.toLocaleString()
                                : 'Not tracked'
                        }
                    />
                    <StatTile
                        icon={MailOpen}
                        label="Opened"
                        value={
                            campaign.track_opens
                                ? percent(stats.opened, stats.sent)
                                : '—'
                        }
                        hint={
                            campaign.track_opens
                                ? `${stats.opened.toLocaleString()} · approximate`
                                : 'Not tracked'
                        }
                    />
                    <StatTile
                        icon={UserMinus}
                        label="Unsubscribed"
                        value={percent(stats.unsubscribed, stats.sent)}
                        hint={stats.unsubscribed.toLocaleString()}
                    />
                    <StatTile
                        icon={MailX}
                        label="Bounced"
                        value={percent(stats.bounced, stats.sent)}
                        hint={stats.bounced.toLocaleString()}
                    />
                    <StatTile
                        icon={ShieldAlert}
                        label="Complaints"
                        value={percent(stats.complained, stats.sent)}
                        hint={stats.complained.toLocaleString()}
                    />
                </dl>

                {stats.failed > 0 && canViewRecipients && (
                    <p className="text-13 text-red-600 dark:text-red-400">
                        {stats.failed.toLocaleString()} could not be sent.
                        Filter recipients by "Failed" to see why.
                    </p>
                )}

                {abTest && (
                    <section className="space-y-3">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="text-base font-semibold">
                                A/B test
                            </h2>
                            {abTest.winner === null &&
                                campaign.status === 'sending' &&
                                can('campaigns.send') && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setConfirmingWinner(true)
                                        }
                                    >
                                        Pick the winner now
                                    </Button>
                                )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {abTest.winner
                                ? `Variant ${abTest.winner.toUpperCase()} won on ${abTest.metric} and went to everyone else.`
                                : `The winner is picked by ${abTest.metric} at ${formatDateTime(abTest.decides_at) ?? 'the end of the test'}.`}
                        </p>
                        <DataTable
                            columns={abColumns}
                            data={abRows}
                            getRowId={(row) => row.variant}
                        />
                    </section>
                )}

                {links.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">Links</h2>
                        <LinkBars links={links} />
                    </section>
                )}

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Recipients</h2>
                    {!canViewRecipients ? (
                        <p className="rounded-xl border bg-card px-5 py-4 text-13 text-muted-foreground shadow-xs">
                            You need permission to view contacts to see who
                            received this email.
                        </p>
                    ) : (
                        <>
                            <div className="flex flex-wrap items-center gap-2">
                                <TableSearch
                                    value={recipientQuery.get('search')}
                                    onSearch={(search) =>
                                        recipientQuery.update({ search })
                                    }
                                    placeholder="Search by email"
                                />
                                <FacetedFilter
                                    title="Status"
                                    options={RECIPIENT_STATUSES}
                                    selected={recipientQuery.values('status')}
                                    onChange={(status) =>
                                        recipientQuery.update({ status })
                                    }
                                />
                                <ResetFilters
                                    active={
                                        recipientQuery.values('status').length >
                                        0
                                    }
                                    onReset={() =>
                                        recipientQuery.update({ status: null })
                                    }
                                />
                            </div>
                            {!recipients ? (
                                <DataTableSkeleton columns={4} />
                            ) : (
                                <>
                                    <DataTable
                                        columns={recipientColumns}
                                        data={recipients.data}
                                        getRowId={byId}
                                        emptyMessage="Nobody here."
                                        sort={recipientQuery.sort}
                                    />
                                    <Pagination
                                        page={recipients}
                                        query={recipientQuery}
                                    />
                                </>
                            )}
                        </>
                    )}
                </section>

                {snapshot && (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">
                            What was sent
                        </h2>
                        <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 border-b px-5 py-4 text-13">
                                <dt className="text-muted-foreground">From</dt>
                                <dd className="min-w-0 truncate">
                                    <span className="font-medium">
                                        {snapshot.from_name}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        &lt;{snapshot.from_email}&gt;
                                    </span>
                                </dd>
                                {snapshot.reply_to && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Reply-to
                                        </dt>
                                        <dd className="min-w-0 truncate">
                                            {snapshot.reply_to}
                                        </dd>
                                    </>
                                )}
                                <dt className="text-muted-foreground">
                                    Subject
                                </dt>
                                <dd className="min-w-0 font-medium">
                                    {snapshot.subject}
                                    {snapshot.preheader && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            · {snapshot.preheader}
                                        </span>
                                    )}
                                </dd>
                            </dl>
                            <iframe
                                title="Sent content"
                                sandbox=""
                                srcDoc={snapshot.html}
                                className="block h-[40rem] w-full bg-white"
                            />
                        </div>
                    </section>
                )}

                <ConfirmDialog
                    open={confirmingWinner}
                    onOpenChange={setConfirmingWinner}
                    title="Send the leading version to everyone else now?"
                    description="The remaining audience gets the version that's ahead right now. This can't be undone."
                    destructive={false}
                    cancelLabel="Not yet"
                    confirmLabel="Pick the winner"
                    onConfirm={() => {
                        setConfirmingWinner(false);
                        router.post(
                            CampaignController.pickWinner.url(campaign.id),
                        );
                    }}
                />

                <ConfirmDialog
                    open={confirmingResend}
                    onOpenChange={setConfirmingResend}
                    title="Resend to people who didn't open?"
                    description="This creates a segment of everyone who received this campaign but hasn't opened it, and a draft copy of the campaign aimed at that segment. Nothing is sent until you schedule the copy, so you can change its subject line first."
                    destructive={false}
                    confirmLabel="Draft the resend"
                    onConfirm={() => {
                        setConfirmingResend(false);
                        router.post(
                            CampaignController.resendToNonOpeners.url(
                                campaign.id,
                            ),
                        );
                    }}
                />

                <ConfirmDialog
                    open={confirmingStop}
                    onOpenChange={setConfirmingStop}
                    title="Stop sending this campaign?"
                    description="Anyone who hasn't been sent it yet won't get it. Emails already sent can't be recalled."
                    cancelLabel="Not yet"
                    confirmLabel="Stop sending"
                    onConfirm={() => {
                        setConfirmingStop(false);
                        router.post(CampaignController.cancel.url(campaign.id));
                    }}
                />

                <ConfirmDialog
                    open={confirmingDelete}
                    onOpenChange={setConfirmingDelete}
                    title={`Delete ${campaign.name}?`}
                    description="The campaign and its delivery history are removed. This can't be undone."
                    confirmLabel="Delete campaign"
                    onConfirm={() =>
                        router.delete(
                            CampaignController.destroy.url(campaign.id),
                        )
                    }
                />
            </PageBody>
        </>
    );
}

ShowCampaign.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};

/**
 * Links ranked by unique clicks, each row backed by a bar sized against the most clicked link.
 */
function LinkBars({ links }: { links: LinkRow[] }) {
    const ranked = [...links].sort(
        (a, b) =>
            b.unique_clicks_count - a.unique_clicks_count ||
            b.clicks_count - a.clicks_count,
    );
    const most = Math.max(1, ranked[0]?.unique_clicks_count ?? 0);

    return (
        <div className="rounded-xl border bg-card p-2 shadow-xs">
            <div
                aria-hidden
                className="flex justify-between px-3 py-2 text-xs text-muted-foreground"
            >
                <span>Link</span>
                <span>Unique · total clicks</span>
            </div>
            <ul className="space-y-1">
                {ranked.map((link) => (
                    <li
                        key={link.id}
                        className="relative flex items-center justify-between gap-4 rounded-md px-3 py-2"
                    >
                        <span
                            aria-hidden
                            className="absolute inset-y-0 left-0 rounded-md bg-sky-500/10 dark:bg-sky-400/15"
                            style={{
                                width: `${(link.unique_clicks_count / most) * 100}%`,
                            }}
                        />
                        <a
                            href={link.url}
                            target="_blank"
                            rel="noreferrer"
                            title={link.url}
                            className="relative min-w-0 truncate text-13 hover:underline"
                        >
                            {readableUrl(link.url)}
                        </a>
                        <span className="relative shrink-0 text-13 tabular-nums">
                            <span className="font-medium">
                                {link.unique_clicks_count.toLocaleString()}
                            </span>
                            <span className="sr-only"> unique clicks, </span>
                            <span className="text-muted-foreground">
                                <span aria-hidden>{' · '}</span>
                                {link.clicks_count.toLocaleString()}
                            </span>
                            <span className="sr-only"> total</span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** A link without its scheme or a trailing slash, as people read it, falling back to the raw text if it does not parse. */
function readableUrl(url: string): string {
    try {
        const parsed = new URL(url);

        return `${parsed.host}${parsed.pathname === '/' ? '' : parsed.pathname}${parsed.search}`;
    } catch {
        return url;
    }
}
