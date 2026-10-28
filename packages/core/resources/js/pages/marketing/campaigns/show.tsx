import { Head, Link, router, usePoll } from '@inertiajs/react';
import CampaignController from '@/actions/Gerenuk/Marketing/Http/Controllers/CampaignController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show as showContact } from '@/routes/marketing/contacts';
import { index as campaignsIndex, show } from '@/routes/marketing/campaigns';
import { CampaignStatusBadge } from '@marketing/components/campaign-status-badge';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { NativeSelect } from '@marketing/components/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { Pagination } from '@marketing/components/pagination';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDateTime, percent } from '@marketing/lib/format';
import type { CampaignSummary, Paginated } from '@marketing/types';

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

export default function ShowCampaign({
    campaign,
    snapshot,
    links,
    recipients,
    recipientStatus,
    abTest,
}: {
    abTest: AbTest;
    campaign: CampaignSummary;
    snapshot: Snapshot;
    links: LinkRow[];
    recipients?: Paginated<Recipient>;
    recipientStatus: string | null;
}) {
    const can = useCan();
    const { stats } = campaign;
    const active =
        campaign.status === 'sending' || campaign.status === 'scheduled';

    usePoll(4000, { only: ['campaign', 'links'] }, { autoStart: active });

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
                    actions={
                        <>
                            <CampaignStatusBadge status={campaign.status} />
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
                                        onClick={() =>
                                            router.post(
                                                CampaignController.cancel.url(
                                                    campaign.id,
                                                ),
                                            )
                                        }
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
                    <Stat
                        label="Recipients"
                        value={stats.recipients.toLocaleString()}
                    />
                    <Stat
                        label="Delivered"
                        value={percent(stats.delivered, stats.sent)}
                        detail={stats.delivered.toLocaleString()}
                    />
                    <Stat
                        label="Clicked"
                        value={
                            campaign.track_clicks
                                ? percent(stats.clicked, stats.sent)
                                : '—'
                        }
                        detail={stats.clicked.toLocaleString()}
                    />
                    <Stat
                        label="Opened"
                        value={
                            campaign.track_opens
                                ? percent(stats.opened, stats.sent)
                                : '—'
                        }
                        detail="approximate"
                    />
                    <Stat
                        label="Unsubscribed"
                        value={percent(stats.unsubscribed, stats.sent)}
                        detail={stats.unsubscribed.toLocaleString()}
                    />
                    <Stat
                        label="Bounced"
                        value={percent(stats.bounced, stats.sent)}
                        detail={stats.bounced.toLocaleString()}
                    />
                    <Stat
                        label="Complaints"
                        value={percent(stats.complained, stats.sent)}
                        detail={stats.complained.toLocaleString()}
                    />
                </dl>

                {stats.failed > 0 && (
                    <p className="text-sm text-red-600 dark:text-red-400">
                        {stats.failed.toLocaleString()} could not be sent.
                        Filter recipients by "Failed" to see why.
                    </p>
                )}

                {abTest && (
                    <section className="space-y-3">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="font-medium">A/B test</h2>
                            {abTest.winner === null &&
                                campaign.status === 'sending' &&
                                can('campaigns.send') && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                CampaignController.pickWinner.url(
                                                    campaign.id,
                                                ),
                                            )
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
                            headers={[
                                'Variant',
                                'Subject',
                                'Sent',
                                'Opened',
                                'Clicked',
                            ]}
                        >
                            {(['a', 'b'] as const).map((variant) => (
                                <tr key={variant}>
                                    <Cell className="font-medium">
                                        {variant.toUpperCase()}{' '}
                                        {abTest.winner === variant && (
                                            <Badge variant="secondary">
                                                Winner
                                            </Badge>
                                        )}
                                    </Cell>
                                    <Cell className="max-w-sm truncate">
                                        {variant === 'b'
                                            ? (snapshot?.subject_b ??
                                              snapshot?.subject)
                                            : snapshot?.subject}
                                    </Cell>
                                    <Cell>
                                        {abTest.results[
                                            variant
                                        ].sent.toLocaleString()}
                                    </Cell>
                                    <Cell>
                                        {percent(
                                            abTest.results[variant].opened,
                                            abTest.results[variant].sent,
                                        )}
                                    </Cell>
                                    <Cell>
                                        {percent(
                                            abTest.results[variant].clicked,
                                            abTest.results[variant].sent,
                                        )}
                                    </Cell>
                                </tr>
                            ))}
                        </DataTable>
                    </section>
                )}

                {links.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="font-medium">Links</h2>
                        <DataTable
                            headers={['Link', 'Unique clicks', 'Total clicks']}
                        >
                            {links.map((link) => (
                                <tr key={link.id}>
                                    <Cell className="max-w-xl truncate">
                                        <a
                                            href={link.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="hover:underline"
                                        >
                                            {link.url}
                                        </a>
                                    </Cell>
                                    <Cell>
                                        {link.unique_clicks_count.toLocaleString()}
                                    </Cell>
                                    <Cell>
                                        {link.clicks_count.toLocaleString()}
                                    </Cell>
                                </tr>
                            ))}
                        </DataTable>
                    </section>
                )}

                <section className="space-y-3">
                    <div className="flex items-center justify-between gap-4">
                        <h2 className="font-medium">Recipients</h2>
                        <NativeSelect
                            aria-label="Recipient status"
                            value={recipientStatus ?? ''}
                            onChange={(event) =>
                                router.get(
                                    show.url(campaign.id),
                                    event.target.value
                                        ? { recipients: event.target.value }
                                        : {},
                                    {
                                        only: ['recipients', 'recipientStatus'],
                                        preserveScroll: true,
                                        preserveState: true,
                                    },
                                )
                            }
                        >
                            <option value="">Everyone</option>
                            {[
                                'queued',
                                'sent',
                                'delivered',
                                'bounced',
                                'complained',
                                'failed',
                                'skipped',
                            ].map((status) => (
                                <option key={status} value={status}>
                                    {status.charAt(0).toUpperCase() +
                                        status.slice(1)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    {!recipients ? (
                        <Spinner />
                    ) : (
                        <>
                            <DataTable
                                headers={[
                                    'Email',
                                    'Status',
                                    'Opened',
                                    'Clicked',
                                ]}
                                empty={
                                    recipients.data.length === 0 && (
                                        <EmptyRow message="Nobody here." />
                                    )
                                }
                            >
                                {recipients.data.map((recipient) => (
                                    <tr key={recipient.id}>
                                        <Cell>
                                            <Link
                                                href={showContact(
                                                    recipient.contact_id,
                                                )}
                                                className="hover:underline"
                                            >
                                                {recipient.email}
                                            </Link>
                                            {recipient.error && (
                                                <p className="text-xs text-red-600">
                                                    {recipient.error}
                                                </p>
                                            )}
                                        </Cell>
                                        <Cell>
                                            <Badge variant="outline">
                                                {recipient.status}
                                            </Badge>
                                        </Cell>
                                        <Cell>
                                            {recipient.opened ? 'Yes' : ''}
                                        </Cell>
                                        <Cell>
                                            {recipient.clicked ? 'Yes' : ''}
                                        </Cell>
                                    </tr>
                                ))}
                            </DataTable>
                            <Pagination page={recipients} />
                        </>
                    )}
                </section>

                {snapshot && (
                    <section className="space-y-3">
                        <h2 className="font-medium">What was sent</h2>
                        <div className="space-y-1 text-sm">
                            <p>
                                <span className="text-muted-foreground">
                                    Subject:
                                </span>{' '}
                                {snapshot.subject}
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    From:
                                </span>{' '}
                                {snapshot.from_name} &lt;{snapshot.from_email}
                                &gt;
                            </p>
                        </div>
                        <iframe
                            title="Sent content"
                            sandbox=""
                            srcDoc={snapshot.html}
                            className="h-[40rem] w-full max-w-3xl rounded-md border bg-white"
                        />
                    </section>
                )}
            </PageBody>
        </>
    );
}

function Stat({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail?: string;
}) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-2xl font-semibold tabular-nums">{value}</dd>
            {detail && (
                <dd className="text-xs text-muted-foreground">{detail}</dd>
            )}
        </div>
    );
}

ShowCampaign.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
