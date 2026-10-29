import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CalendarClock, Plus, Workflow } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { edit as editAutomation } from '@/routes/marketing/automations';
import {
    edit as editCampaign,
    index as campaignsIndex,
    show as showCampaign,
} from '@/routes/marketing/campaigns';
import { home } from '@/routes/marketing';
import type { DailyCount } from '@marketing/components/charts';
import { Sparkline, TrendChart } from '@marketing/components/charts';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan, useWorkspace } from '@marketing/hooks/use-workspace';
import { formatDate, formatDateTime, percent } from '@marketing/lib/format';
import type { CampaignSummary } from '@marketing/types';

type Props = {
    days: number;
    audience: { subscribed: number; added: DailyCount[] } | null;
    sending: {
        sent: DailyCount[];
        totals: { sent: number; opened: number; clicked: number };
        next: CampaignSummary | null;
        drafts: CampaignSummary[];
        recent: CampaignSummary[];
    } | null;
    automations: { id: number; name: string; active_count: number }[] | null;
};

const total = (series: DailyCount[]) =>
    series.reduce((sum, day) => sum + day.count, 0);

export default function Home({ days, audience, sending, automations }: Props) {
    const workspace = useWorkspace();
    const canCreate = useCan()('campaigns.manage');

    return (
        <>
            <Head title="Home" />

            <PageBody>
                <PageHeader
                    title="Home"
                    description={`${workspace?.current.name ?? 'Your workspace'} over the last ${days} days.`}
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {audience && (
                        <Stat
                            label="Subscribed contacts"
                            value={audience.subscribed.toLocaleString()}
                            detail={`+${total(audience.added).toLocaleString()} added`}
                        >
                            <Sparkline
                                data={audience.added}
                                label="Contacts added per day"
                                className="text-emerald-600/70 dark:text-emerald-400/70"
                            />
                        </Stat>
                    )}
                    {sending && (
                        <>
                            <Stat
                                label="Emails sent"
                                value={sending.totals.sent.toLocaleString()}
                                detail={`in ${days} days`}
                            >
                                <Sparkline
                                    data={sending.sent}
                                    label="Emails sent per day"
                                    className="text-sky-600/70 dark:text-sky-400/70"
                                />
                            </Stat>
                            <Stat
                                label="Open rate"
                                value={percent(
                                    sending.totals.opened,
                                    sending.totals.sent,
                                )}
                                detail={`${sending.totals.opened.toLocaleString()} opened · approximate`}
                            />
                            <Stat
                                label="Click rate"
                                value={percent(
                                    sending.totals.clicked,
                                    sending.totals.sent,
                                )}
                                detail={`${sending.totals.clicked.toLocaleString()} clicked`}
                            />
                        </>
                    )}
                </div>

                {sending && (
                    <div className="grid gap-4 lg:grid-cols-3">
                        <Card className="gap-4 py-5 lg:col-span-2">
                            <CardHeader className="px-5">
                                <CardTitle className="text-base">
                                    Emails sent per day
                                </CardTitle>
                                <CardDescription>
                                    From campaigns and automations.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="px-3">
                                <TrendChart
                                    data={sending.sent}
                                    label="Emails sent per day"
                                />
                            </CardContent>
                        </Card>

                        <Card className="gap-4 py-5">
                            <CardHeader className="px-5">
                                <CardTitle className="text-base">
                                    Coming up
                                </CardTitle>
                                <CardDescription>
                                    Scheduled sends and unfinished drafts.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-1 px-2">
                                {sending.next && (
                                    <Row
                                        href={showCampaign(sending.next.id)}
                                        icon={
                                            <CalendarClock className="size-4 text-sky-600 dark:text-sky-400" />
                                        }
                                        title={sending.next.name}
                                        meta={`Goes out ${formatDateTime(sending.next.scheduled_at)}`}
                                    />
                                )}
                                {sending.drafts.map((draft) => (
                                    <Row
                                        key={draft.id}
                                        href={editCampaign(draft.id)}
                                        title={draft.name}
                                        meta={`Draft · edited ${formatDate(draft.updated_at ?? draft.created_at)}`}
                                    />
                                ))}
                                {!sending.next &&
                                    sending.drafts.length === 0 && (
                                        <Empty
                                            message="Nothing scheduled."
                                            action={
                                                canCreate && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={campaignsIndex(
                                                                {
                                                                    query: {
                                                                        new: 1,
                                                                    },
                                                                },
                                                            )}
                                                        >
                                                            <Plus />
                                                            New campaign
                                                        </Link>
                                                    </Button>
                                                )
                                            }
                                        />
                                    )}
                            </CardContent>
                        </Card>
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    {sending && (
                        <Card className="gap-4 py-5 lg:col-span-2">
                            <CardHeader className="flex flex-row items-start justify-between px-5">
                                <div className="space-y-1.5">
                                    <CardTitle className="text-base">
                                        Recent campaigns
                                    </CardTitle>
                                    <CardDescription>
                                        How the last few sends did.
                                    </CardDescription>
                                </div>
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={campaignsIndex()}>
                                        All campaigns
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            </CardHeader>
                            <CardContent className="px-2">
                                {sending.recent.length === 0 ? (
                                    <Empty message="Nothing sent yet." />
                                ) : (
                                    sending.recent.map((campaign) => (
                                        <Link
                                            key={campaign.id}
                                            href={showCampaign(campaign.id)}
                                            className="grid grid-cols-[1fr_auto_auto] items-center gap-6 rounded-md px-3 py-2.5 transition-colors hover:bg-muted/60"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium">
                                                    {campaign.name}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {formatDate(
                                                        campaign.sent_at,
                                                    )}{' '}
                                                    ·{' '}
                                                    {campaign.stats.sent.toLocaleString()}{' '}
                                                    sent
                                                </span>
                                            </span>
                                            <Metric
                                                label="Opened"
                                                value={
                                                    campaign.track_opens
                                                        ? percent(
                                                              campaign.stats
                                                                  .opened,
                                                              campaign.stats
                                                                  .sent,
                                                          )
                                                        : '—'
                                                }
                                            />
                                            <Metric
                                                label="Clicked"
                                                value={
                                                    campaign.track_clicks
                                                        ? percent(
                                                              campaign.stats
                                                                  .clicked,
                                                              campaign.stats
                                                                  .sent,
                                                          )
                                                        : '—'
                                                }
                                            />
                                        </Link>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {automations && (
                        <Card className="gap-4 py-5">
                            <CardHeader className="px-5">
                                <CardTitle className="text-base">
                                    Live automations
                                </CardTitle>
                                <CardDescription>
                                    People in each one right now.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-1 px-2">
                                {automations.length === 0 ? (
                                    <Empty message="No automations are running." />
                                ) : (
                                    automations.map((automation) => (
                                        <Row
                                            key={automation.id}
                                            href={editAutomation(automation.id)}
                                            icon={
                                                <Workflow className="size-4 text-violet-600 dark:text-violet-400" />
                                            }
                                            title={automation.name}
                                            meta={`${automation.active_count.toLocaleString()} in it now`}
                                        />
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </PageBody>
        </>
    );
}

function Stat({
    label,
    value,
    detail,
    children,
}: {
    label: string;
    value: string;
    detail: string;
    children?: ReactNode;
}) {
    return (
        <Card className="gap-3 py-4">
            <CardContent className="space-y-1 px-5">
                <p className="text-13 text-muted-foreground">{label}</p>
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
                <p className="text-xs text-muted-foreground">{detail}</p>
            </CardContent>
            {children && <div className="px-5">{children}</div>}
        </Card>
    );
}

function Row({
    href,
    icon,
    title,
    meta,
}: {
    href: ReturnType<typeof home>;
    icon?: ReactNode;
    title: string;
    meta: string;
}) {
    return (
        <Link
            href={href}
            className="flex items-center gap-3 rounded-md px-3 py-2.5 transition-colors hover:bg-muted/60"
        >
            {icon && (
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-background">
                    {icon}
                </span>
            )}
            <span className="min-w-0">
                <span className="block truncate text-sm font-medium">
                    {title}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {meta}
                </span>
            </span>
        </Link>
    );
}

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <span className="text-right">
            <span className="block text-sm font-medium tabular-nums">
                {value}
            </span>
            <span className="block text-xs text-muted-foreground">{label}</span>
        </span>
    );
}

function Empty({ message, action }: { message: string; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-3 px-3 py-6 text-center">
            <p className="text-sm text-muted-foreground">{message}</p>
            {action}
        </div>
    );
}

Home.layout = {
    breadcrumbs: [{ title: 'Home', href: home() }],
};
