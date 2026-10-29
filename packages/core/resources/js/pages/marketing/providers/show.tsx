import { Form, Head, Link } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import ProviderController from '@/actions/Gerenuk/Marketing/Http/Controllers/ProviderController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { edit, index as providersIndex } from '@/routes/marketing/providers';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { formatDateTime, percent } from '@marketing/lib/format';
import type { ProviderTypeDefinition } from '@marketing/types';

type Provider = {
    id: number;
    name: string;
    type: ProviderTypeDefinition;
    from_name: string | null;
    from_email: string | null;
    max_per_second: number;
    daily_limit: number | null;
    last_sent_at: string | null;
    last_webhook_at: string | null;
    webhook_url: string | null;
    has_webhook_secret: boolean;
};

type Stats = {
    sent: number;
    delivered: number;
    bounced: number;
    complained: number;
};

const webhookSteps: Record<string, string> = {
    signing_secret:
        "Create a webhook in your provider dashboard pointing at this URL, with delivery, bounce, complaint and unsubscribe events, then paste its signing secret into this provider's settings.",
    basic_auth:
        'Create a webhook in your provider dashboard pointing at this URL, with delivery, bounce, complaint and unsubscribe events. The URL already includes the credentials it needs.',
    sns: 'In Amazon SES, give your configuration set an event destination that publishes delivery, bounce, complaint and reject events to an SNS topic. Subscribe this URL to the topic over HTTPS; the subscription is confirmed automatically.',
};

export default function ShowProvider({
    provider,
    stats,
}: {
    provider: Provider;
    stats: Stats;
}) {
    const [copied, copy] = useClipboard();
    const verification = provider.type.webhook_verification;
    const webhooksSilent =
        verification !== 'none' &&
        !provider.last_webhook_at &&
        provider.last_sent_at;

    return (
        <>
            <Head title={provider.name} />

            <PageBody>
                <PageHeader
                    title={provider.name}
                    description={`${provider.type.label} · up to ${provider.max_per_second} emails a second${provider.daily_limit ? ` · ${provider.daily_limit.toLocaleString()} a day` : ''}`}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={edit(provider.id)}>Edit</Link>
                            </Button>
                            <ConfirmDelete
                                url={ProviderController.destroy.url(
                                    provider.id,
                                )}
                                title={`Remove ${provider.name}?`}
                                description="Its credentials are deleted. Campaigns using it will need another provider."
                            />
                        </>
                    }
                />

                <dl className="grid gap-4 sm:grid-cols-4">
                    <Stat
                        label="Sent, last 7 days"
                        value={stats.sent.toLocaleString()}
                    />
                    <Stat
                        label="Delivered"
                        value={percent(stats.delivered, stats.sent)}
                    />
                    <Stat
                        label="Bounced"
                        value={percent(stats.bounced, stats.sent)}
                    />
                    <Stat
                        label="Complaints"
                        value={percent(stats.complained, stats.sent)}
                    />
                </dl>

                <section className="max-w-3xl space-y-3 rounded-xl border p-4">
                    <h2 className="text-base font-semibold">
                        Delivery webhooks
                    </h2>
                    {verification === 'none' ? (
                        <p className="text-sm text-muted-foreground">
                            {provider.type.label} does not send delivery
                            webhooks here, so bounces and complaints are not
                            recorded automatically. Keep an eye on your
                            provider's dashboard and add bounced addresses to
                            suppressions.
                        </p>
                    ) : (
                        <>
                            <p className="text-sm text-muted-foreground">
                                {webhookSteps[verification]}
                            </p>
                            <div className="flex gap-2">
                                <Input
                                    readOnly
                                    value={provider.webhook_url ?? ''}
                                    className="font-mono text-xs"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label="Copy webhook URL"
                                    onClick={() =>
                                        copy(provider.webhook_url ?? '')
                                    }
                                >
                                    {copied === provider.webhook_url ? (
                                        <Check />
                                    ) : (
                                        <Copy />
                                    )}
                                </Button>
                            </div>
                            {verification === 'signing_secret' &&
                                !provider.has_webhook_secret && (
                                    <p className="text-sm text-amber-700 dark:text-amber-400">
                                        No signing secret saved yet, so webhooks
                                        will be rejected. Add it under Edit.
                                    </p>
                                )}
                            <p className="text-sm">
                                Last webhook received:{' '}
                                {formatDateTime(provider.last_webhook_at) ??
                                    'never'}
                            </p>
                            {webhooksSilent && (
                                <p className="text-sm text-amber-700 dark:text-amber-400">
                                    Emails have been sent but no webhooks have
                                    arrived. Check the webhook is set up.
                                </p>
                            )}
                        </>
                    )}
                </section>

                <section className="max-w-3xl space-y-3 rounded-xl border p-4">
                    <h2 className="text-base font-semibold">Send a test</h2>
                    <Form
                        {...ProviderController.test.form(provider.id)}
                        options={{ preserveScroll: true }}
                        className="grid items-start gap-3 sm:grid-cols-[1fr_1fr_auto]"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="test-from">From</Label>
                                    <Input
                                        id="test-from"
                                        name="from"
                                        type="email"
                                        defaultValue={provider.from_email ?? ''}
                                        required
                                    />
                                    <InputError message={errors.from} />
                                </div>
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="test-to">To</Label>
                                    <Input
                                        id="test-to"
                                        name="to"
                                        type="email"
                                        required
                                    />
                                    <InputError message={errors.to} />
                                </div>
                                <Button
                                    className="sm:mt-6"
                                    disabled={processing}
                                >
                                    Send test
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </PageBody>
        </>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-2xl font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

ShowProvider.layout = {
    breadcrumbs: [{ title: 'Providers', href: providersIndex() }],
};
