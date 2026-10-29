import { Head, Link } from '@inertiajs/react';
import { Mail, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    create,
    index as providersIndex,
    show,
} from '@/routes/marketing/providers';
import { StatusBadge } from '@marketing/components/status-badge';
import type { StatusTone } from '@marketing/components/status-badge';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { formatDateTime } from '@marketing/lib/format';

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
                    description="Campaigns send through your own provider account. Connect one or more."
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                Connect provider
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {providers.map((provider) => (
                        <ProviderCard key={provider.id} provider={provider} />
                    ))}
                    <Link
                        href={create()}
                        className="flex min-h-48 flex-col items-center justify-center gap-2 rounded-xl border border-dashed bg-muted/40 p-6 text-center text-sm text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground"
                    >
                        <Plus className="size-4" />
                        {providers.length === 0
                            ? 'Connect your first provider to start sending'
                            : 'Connect another provider'}
                    </Link>
                </div>
            </PageBody>
        </>
    );
}

/**
 * A provider connection at a glance: whether it has sent, whether its webhooks report deliveries back, and its limits.
 */
function ProviderCard({ provider }: { provider: ProviderRow }) {
    const awaitingWebhooks =
        provider.receives_webhooks &&
        provider.last_sent_at !== null &&
        provider.last_webhook_at === null;
    const health: { tone: StatusTone; label: string } = awaitingWebhooks
        ? { tone: 'warning', label: 'Waiting for webhooks' }
        : provider.last_sent_at
          ? { tone: 'success', label: 'In use' }
          : { tone: 'neutral', label: 'Not used yet' };

    return (
        <Link
            href={show(provider.id)}
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-xs transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-muted/40">
                        <Mail className="size-4 text-muted-foreground" />
                    </span>
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

            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 border-t pt-4 text-xs">
                <div>
                    <dt className="text-muted-foreground">Last send</dt>
                    <dd className="mt-0.5 text-13">
                        {formatDateTime(provider.last_sent_at) ?? 'Never'}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Last webhook</dt>
                    <dd className="mt-0.5 text-13">
                        {provider.receives_webhooks
                            ? (formatDateTime(provider.last_webhook_at) ??
                              'None yet')
                            : 'Not supported'}
                    </dd>
                </div>
                <div className="col-span-2">
                    <dt className="text-muted-foreground">Limits</dt>
                    <dd className="mt-0.5 text-13 tabular-nums">
                        Up to {provider.max_per_second.toLocaleString()} a
                        second
                        {provider.daily_limit
                            ? `, ${provider.daily_limit.toLocaleString()} a day`
                            : ', no daily cap'}
                    </dd>
                </div>
            </dl>
        </Link>
    );
}

ProvidersIndex.layout = {
    breadcrumbs: [{ title: 'Providers', href: providersIndex() }],
};
