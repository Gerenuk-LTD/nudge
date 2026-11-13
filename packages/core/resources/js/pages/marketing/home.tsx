import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    CalendarClock,
    Check,
    MailOpen,
    MousePointerClick,
    Plus,
    Send,
    Upload,
    Users,
    Workflow,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    edit as editAutomation,
    index as automationsIndex,
} from '@/routes/marketing/automations';
import { create as createImport } from '@/routes/marketing/imports';
import {
    edit as editCampaign,
    index as campaignsIndex,
    show as showCampaign,
} from '@/routes/marketing/campaigns';
import { home } from '@/routes/marketing';
import type { DailyCount } from '@marketing/components/charts';
import { Sparkline, TrendChart } from '@marketing/components/charts';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { Shout } from '@marketing/components/shout';
import { StatIcon } from '@marketing/components/stat-tile';
import { useCan, useWorkspace } from '@marketing/hooks/use-workspace';
import { formatDate, formatDateTime, percent } from '@marketing/lib/format';
import type { CampaignSummary } from '@marketing/types';

type Totals = { sent: number; opened: number; clicked: number };

type Notice = {
    key: string;
    type: 'info' | 'warning' | 'danger';
    heading: string;
    body: string;
    action: { label: string; href: string } | null;
};

type SetupStep = { key: string; label: string; done: boolean; href: string };

type Props = {
    days: number;
    notices: Notice[];
    setup: SetupStep[] | null;
    audience: {
        subscribed: number;
        added: DailyCount[];
        previous_added: number;
    } | null;
    sending: {
        sent: DailyCount[];
        totals: Totals;
        previous: Totals;
        next: CampaignSummary | null;
        drafts: CampaignSummary[];
        recent: CampaignSummary[];
    } | null;
    automations: { id: number; name: string; active_count: number }[] | null;
};

const total = (series: DailyCount[]) =>
    series.reduce((sum, day) => sum + day.count, 0);

/** A greeting for the time of day, in the viewer's own time zone. */
function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12
        ? 'Good morning'
        : hour < 18
          ? 'Good afternoon'
          : 'Good evening';
}

/** The change from the previous period as a share, or null when there is nothing to compare with. */
function change(current: number, previous: number): number | null {
    return previous === 0 ? null : (current - previous) / previous;
}

/** A rate as a fraction, or null without sends. */
const rate = (count: number, sent: number) =>
    sent === 0 ? null : count / sent;

export default function Home({
    days,
    notices,
    setup,
    audience,
    sending,
    automations,
}: Props) {
    const workspace = useWorkspace();
    const can = useCan();
    const canCreate = can('campaigns.manage');
    const user = usePage<{ auth: { user: { name: string } } }>().props.auth
        .user;
    const firstName = user.name.split(/\s+/)[0];

    return (
        <>
            <Head title="Home" />

            <PageBody>
                <PageHeader
                    title={`${greeting()}, ${firstName}`}
                    description={`Here's how ${workspace?.current.name ?? 'your workspace'} has done over the last ${days} days.`}
                    actions={
                        <>
                            {can('contacts.import') && (
                                <Button variant="outline" asChild>
                                    <Link href={createImport()}>
                                        <Upload />
                                        Import contacts
                                    </Link>
                                </Button>
                            )}
                            {canCreate && (
                                <Button asChild>
                                    <Link
                                        href={campaignsIndex({
                                            query: { new: 1 },
                                        })}
                                    >
                                        <Plus />
                                        New campaign
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {notices.length > 0 && (
                    <div className="space-y-2">
                        {notices.map((notice) => (
                            <Shout
                                key={notice.key}
                                type={notice.type}
                                heading={notice.heading}
                                dismissKey={
                                    notice.type === 'info'
                                        ? `home:${notice.key}`
                                        : undefined
                                }
                                actions={
                                    notice.action && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="bg-card"
                                            asChild
                                        >
                                            <Link href={notice.action.href}>
                                                {notice.action.label}
                                            </Link>
                                        </Button>
                                    )
                                }
                            >
                                {notice.body}
                            </Shout>
                        ))}
                    </div>
                )}

                {setup && <SetupChecklist steps={setup} />}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {audience && (
                        <Stat
                            icon={Users}
                            label="Subscribed contacts"
                            value={audience.subscribed.toLocaleString()}
                            detail={`+${total(audience.added).toLocaleString()} added`}
                            trend={change(
                                total(audience.added),
                                audience.previous_added,
                            )}
                            trendLabel="new contacts"
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
                                icon={Send}
                                label="Emails sent"
                                value={sending.totals.sent.toLocaleString()}
                                detail={`in ${days} days`}
                                trend={change(
                                    sending.totals.sent,
                                    sending.previous.sent,
                                )}
                            >
                                <Sparkline
                                    data={sending.sent}
                                    label="Emails sent per day"
                                    className="text-sky-600/70 dark:text-sky-400/70"
                                />
                            </Stat>
                            <Stat
                                icon={MailOpen}
                                label="Open rate"
                                value={percent(
                                    sending.totals.opened,
                                    sending.totals.sent,
                                )}
                                detail={`${sending.totals.opened.toLocaleString()} opened · approximate`}
                                points={pointsChange(
                                    rate(
                                        sending.totals.opened,
                                        sending.totals.sent,
                                    ),
                                    rate(
                                        sending.previous.opened,
                                        sending.previous.sent,
                                    ),
                                )}
                            />
                            <Stat
                                icon={MousePointerClick}
                                label="Click rate"
                                value={percent(
                                    sending.totals.clicked,
                                    sending.totals.sent,
                                )}
                                detail={`${sending.totals.clicked.toLocaleString()} clicked`}
                                points={pointsChange(
                                    rate(
                                        sending.totals.clicked,
                                        sending.totals.sent,
                                    ),
                                    rate(
                                        sending.previous.clicked,
                                        sending.previous.sent,
                                    ),
                                )}
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
                                    <Empty
                                        message="No automations are running. Welcome new subscribers or win back quiet ones on autopilot."
                                        action={
                                            can('automations.manage') && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link
                                                        href={automationsIndex()}
                                                    >
                                                        <Workflow />
                                                        Set one up
                                                    </Link>
                                                </Button>
                                            )
                                        }
                                    />
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

/** The change in a rate, in percentage points, or null when either period had no sends. */
function pointsChange(current: number | null, previous: number | null) {
    return current === null || previous === null
        ? null
        : (current - previous) * 100;
}

function Stat({
    icon,
    label,
    value,
    detail,
    trend = null,
    trendLabel,
    points = null,
    children,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
    /** Change from the previous period, as a share. */
    trend?: number | null;
    trendLabel?: string;
    /** Change from the previous period, in percentage points. */
    points?: number | null;
    children?: ReactNode;
}) {
    const delta = trend ?? points;
    const shown =
        delta === null
            ? null
            : trend !== null
              ? `${Math.abs(Math.round(trend * 100))}%`
              : `${Math.abs(delta).toFixed(1)} pts`;

    return (
        <Card className="gap-3 py-4">
            <CardContent className="space-y-1 px-5">
                <div className="flex items-center justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-2.5">
                        <StatIcon icon={icon} />
                        <p className="truncate text-13 text-muted-foreground">
                            {label}
                        </p>
                    </div>
                    {shown !== null && delta !== null && (
                        <span
                            title={`Compared with the ${trendLabel ? `${trendLabel} in the ` : ''}previous period`}
                            className={cn(
                                'inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums',
                                delta > 0 &&
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400',
                                delta < 0 &&
                                    'bg-red-50 text-red-700 dark:bg-red-400/10 dark:text-red-400',
                                delta === 0 && 'bg-muted text-muted-foreground',
                            )}
                        >
                            {delta > 0 ? (
                                <ArrowUpRight className="size-3" />
                            ) : delta < 0 ? (
                                <ArrowDownRight className="size-3" />
                            ) : null}
                            {shown}
                        </span>
                    )}
                </div>
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
                <p className="text-xs text-muted-foreground">
                    {detail}
                    {delta !== null && ' · vs previous period'}
                </p>
            </CardContent>
            {children && <div className="px-5">{children}</div>}
        </Card>
    );
}

/**
 * The first steps for a new workspace, with how far along it is. It goes once every step is done.
 */
function SetupChecklist({ steps }: { steps: SetupStep[] }) {
    const done = steps.filter((step) => step.done).length;
    const next = steps.find((step) => !step.done);

    return (
        <Card className="gap-0 py-0">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div className="space-y-0.5">
                    <p className="font-semibold">Get set up</p>
                    <p className="text-13 text-muted-foreground">
                        {done} of {steps.length} done. A few steps and you're
                        ready to send.
                    </p>
                </div>
                <div
                    className="h-1.5 w-40 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-label="Setup progress"
                    aria-valuenow={done}
                    aria-valuemax={steps.length}
                >
                    <div
                        className="h-full rounded-full bg-emerald-500 transition-[width]"
                        style={{ width: `${(done / steps.length) * 100}%` }}
                    />
                </div>
            </div>
            <ol className="grid sm:grid-cols-2 lg:grid-cols-4">
                {steps.map((step, index) => (
                    <li
                        key={step.key}
                        className="border-b last:border-b-0 lg:border-r lg:border-b-0 lg:last:border-r-0 sm:[&:nth-child(odd)]:border-r"
                    >
                        <Link
                            href={step.href}
                            className={cn(
                                'flex h-full items-center gap-3 px-5 py-4 transition-colors hover:bg-muted/50',
                                step.done && 'text-muted-foreground',
                            )}
                        >
                            <span
                                className={cn(
                                    'flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-medium tabular-nums',
                                    step.done &&
                                        'border-emerald-600/30 bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400',
                                    step === next &&
                                        'border-foreground bg-foreground text-background',
                                )}
                            >
                                {step.done ? (
                                    <Check className="size-3.5" />
                                ) : (
                                    index + 1
                                )}
                            </span>
                            <span className="min-w-0 flex-1 text-sm font-medium">
                                {step.label}
                            </span>
                            {step === next && (
                                <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
                            )}
                        </Link>
                    </li>
                ))}
            </ol>
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
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/40 text-muted-foreground">
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
            <p className="max-w-xs text-sm text-muted-foreground">{message}</p>
            {action}
        </div>
    );
}

Home.layout = {
    breadcrumbs: [{ title: 'Home', href: home() }],
};
