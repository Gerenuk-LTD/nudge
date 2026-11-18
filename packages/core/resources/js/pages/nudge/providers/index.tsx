import { Head, Link } from '@inertiajs/react';
import { Plus, Send } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    create,
    index as providersIndex,
    show,
} from '@/routes/nudge/providers';
import { EmptyState } from '@nudge/components/empty-state';
import { PageBody, PageHeader } from '@nudge/components/page-header';
import {
    BOUNCE_DANGER,
    BOUNCE_WARNING,
    ProviderMark,
    providerHealth,
} from '@nudge/components/provider-mark';
import { StatusBadge } from '@nudge/components/status-badge';
import { formatDateTime } from '@nudge/lib/format';

type ProviderRow = {
    id: number;
    name: string;
    type: string;
    from_email: string | null;
    last_sent_at: string | null;
    last_webhook_at: string | null;
    receives_webhooks: boolean;
    max_per_second: number;
    daily_limit: number | null;
    sent_7d: number;
    bounced_7d: number;
};

export default function ProvidersIndex({
    providers,
}: {
    providers: ProviderRow[];
}) {
    return (
        <>
            <Head title="Providers" />

            <PageBody>
                <PageHeader
                    title="Sending providers"
                    description="Emails go out through your own account with a sending service. Connect one or more."
                    actions={
                        providers.length > 0 && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus />
                                    Connect provider
                                </Link>
                            </Button>
                        )
                    }
                />

                {providers.length === 0 ? (
                    <EmptyState
                        icon={Send}
                        title="No provider connected"
                        description="Campaigns and automations need a sending service to go out. Connect the account you already use, such as Amazon SES, Postmark, Mailgun or plain SMTP."
                        actions={
                            <Button size="sm" className="mt-1" asChild>
                                <Link href={create()}>
                                    <Plus />
                                    Connect provider
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {providers.map((provider) => (
                            <ProviderCard
                                key={provider.id}
                                provider={provider}
                            />
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

/**
 * A provider connection at a glance: whether it is working, how the last week's sending went, and its limits.
 */
function ProviderCard({ provider }: { provider: ProviderRow }) {
    const health = providerHealth(provider);
    const bounceRate =
        provider.sent_7d > 0 ? provider.bounced_7d / provider.sent_7d : null;

    return (
        <Link
            href={show(provider.id)}
            className="flex flex-col rounded-xl border bg-card shadow-xs transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm"
        >
            <div className="flex items-start justify-between gap-3 p-5 pb-4">
                <div className="flex min-w-0 items-center gap-3">
                    <ProviderMark label={provider.type} />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold">
                            {provider.name}
                        </p>
                        <p className="truncate text-13 text-muted-foreground">
                            {provider.type}
                            {provider.from_email && ` · ${provider.from_email}`}
                        </p>
                    </div>
                </div>
                <StatusBadge tone={health.tone}>{health.label}</StatusBadge>
            </div>

            <dl className="grid grid-cols-2 divide-x border-t">
                <div className="px-5 py-3">
                    <dt className="text-xs text-muted-foreground">
                        Sent, last 7 days
                    </dt>
                    <dd className="mt-0.5 text-lg font-semibold tabular-nums">
                        {provider.sent_7d.toLocaleString()}
                    </dd>
                </div>
                <div className="px-5 py-3">
                    <dt className="text-xs text-muted-foreground">
                        Bounce rate
                    </dt>
                    <dd
                        className={cn(
                            'mt-0.5 text-lg font-semibold tabular-nums',
                            !provider.receives_webhooks &&
                                'text-sm font-normal text-muted-foreground',
                            provider.receives_webhooks &&
                                bounceRate !== null &&
                                bounceRate > BOUNCE_DANGER &&
                                'text-red-600 dark:text-red-400',
                            provider.receives_webhooks &&
                                bounceRate !== null &&
                                bounceRate > BOUNCE_WARNING &&
                                bounceRate <= BOUNCE_DANGER &&
                                'text-amber-600 dark:text-amber-400',
                        )}
                    >
                        {!provider.receives_webhooks
                            ? 'Not tracked'
                            : bounceRate === null
                              ? '—'
                              : `${(bounceRate * 100).toFixed(1)}%`}
                    </dd>
                </div>
            </dl>

            <p className="mt-auto border-t bg-muted/40 px-5 py-2.5 text-xs text-muted-foreground tabular-nums">
                {provider.last_sent_at
                    ? `Last sent ${formatDateTime(provider.last_sent_at)}`
                    : 'Nothing sent yet'}{' '}
                · up to {provider.max_per_second.toLocaleString()}/s
                {provider.daily_limit
                    ? `, ${provider.daily_limit.toLocaleString()}/day`
                    : ''}
            </p>
        </Link>
    );
}

ProvidersIndex.layout = {
    breadcrumbs: [{ title: 'Providers', href: providersIndex() }],
};
