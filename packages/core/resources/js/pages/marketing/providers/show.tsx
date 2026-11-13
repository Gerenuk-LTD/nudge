import {
    Head,
    Link,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import {
    Check,
    CircleCheck,
    Copy,
    Ellipsis,
    Pencil,
    Send,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import ProviderController from '@/actions/Gerenuk/Marketing/Http/Controllers/ProviderController';
import InputError from '@/components/input-error';
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
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import { StatTile } from '@marketing/components/stat-tile';
import {
    edit,
    index as providersIndex,
    show as showProvider,
} from '@/routes/marketing/providers';
import { PageBody } from '@marketing/components/page-header';
import {
    BOUNCE_DANGER,
    BOUNCE_WARNING,
    ProviderMark,
    providerHealth,
} from '@marketing/components/provider-mark';
import { StatusBadge } from '@marketing/components/status-badge';
import { formatDateTime } from '@marketing/lib/format';
import type { ProviderTypeDefinition } from '@marketing/types';
import { Shout } from '@marketing/components/shout';

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

/** Complaint rates above these get mail filtered; mailbox providers act at around 0.3%. */
const COMPLAINT_WARNING = 0.001;
const COMPLAINT_DANGER = 0.003;

const webhookSteps: Record<string, string[]> = {
    signing_secret: [
        'In your provider’s dashboard, add a webhook pointing at the URL below.',
        'Turn on delivery, bounce, complaint and unsubscribe events.',
        'Copy the webhook’s signing secret into this provider’s settings.',
    ],
    basic_auth: [
        'In your provider’s dashboard, add a webhook pointing at the URL below. It already includes the credentials it needs.',
        'Turn on delivery, bounce, complaint and unsubscribe events.',
    ],
    sns: [
        'In Amazon SES, give your configuration set an event destination that publishes delivery, bounce, complaint and reject events to an SNS topic.',
        'Subscribe the URL below to that topic over HTTPS. The subscription is confirmed automatically.',
    ],
};

export default function ShowProvider({
    provider,
    stats,
}: {
    provider: Provider;
    stats: Stats;
}) {
    const [testing, setTesting] = useState(false);
    const [removing, setRemoving] = useState(false);
    const verification = provider.type.webhook_verification;
    const receivesWebhooks = verification !== 'none';
    const health = providerHealth({
        receives_webhooks: receivesWebhooks,
        last_sent_at: provider.last_sent_at,
        last_webhook_at: provider.last_webhook_at,
    });
    const webhookReady =
        verification !== 'signing_secret' || provider.has_webhook_secret;

    const checklist: { done: boolean; label: string; hint: ReactNode }[] = [
        {
            done: true,
            label: 'Connected',
            hint: 'Credentials are saved and encrypted.',
        },
        ...(receivesWebhooks
            ? [
                  {
                      done: provider.last_webhook_at !== null,
                      label: 'Receiving delivery webhooks',
                      hint: provider.last_webhook_at
                          ? `Last one ${formatDateTime(provider.last_webhook_at)}.`
                          : webhookReady
                            ? 'Set up the webhook below so bounces and complaints are recorded.'
                            : 'Add the webhook’s signing secret in this provider’s settings.',
                  },
              ]
            : []),
        {
            done: provider.last_sent_at !== null,
            label: 'Sent an email',
            hint: provider.last_sent_at
                ? `Last one ${formatDateTime(provider.last_sent_at)}.`
                : 'Send a test to check everything works.',
        },
    ];
    const setupDone = checklist.every((item) => item.done);

    setLayoutProps({
        breadcrumbs: [
            { title: 'Providers', href: providersIndex() },
            { title: provider.name, href: showProvider(provider.id) },
        ],
    });

    return (
        <>
            <Head title={provider.name} />

            <PageBody>
                <header className="flex flex-wrap items-center gap-4">
                    <ProviderMark
                        label={provider.type.label}
                        className="size-12 text-sm"
                    />
                    <div className="min-w-0 flex-1 space-y-1">
                        <h1 className="truncate text-2xl font-semibold">
                            {provider.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                            <span>{provider.type.label}</span>
                            <StatusBadge tone={health.tone}>
                                {health.label}
                            </StatusBadge>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setTesting(true)}
                        >
                            <Send />
                            Send test
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={edit(provider.id)}>
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    aria-label="More actions"
                                >
                                    <Ellipsis />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => setRemoving(true)}
                                >
                                    <Trash2 />
                                    Remove provider
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </header>

                {!setupDone && (
                    <section className="rounded-xl border bg-card shadow-xs">
                        <h2 className="border-b px-5 py-3.5 text-sm font-semibold">
                            Finish setting up
                        </h2>
                        <ol className="divide-y">
                            {checklist.map((item) => (
                                <li
                                    key={item.label}
                                    className="flex items-start gap-3 px-5 py-3"
                                >
                                    {item.done ? (
                                        <CircleCheck className="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                    ) : (
                                        <span className="mt-0.5 size-4 shrink-0 rounded-full border-2 border-dashed border-muted-foreground/40" />
                                    )}
                                    <div>
                                        <p
                                            className={cn(
                                                'text-sm font-medium',
                                                item.done &&
                                                    'text-muted-foreground line-through decoration-muted-foreground/40',
                                            )}
                                        >
                                            {item.label}
                                        </p>
                                        <p className="text-13 text-muted-foreground">
                                            {item.hint}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Sent, last 7 days"
                        value={stats.sent.toLocaleString()}
                    />
                    <RateStat
                        label="Delivered"
                        count={stats.delivered}
                        sent={stats.sent}
                        tracked={receivesWebhooks}
                    />
                    <RateStat
                        label="Bounced"
                        count={stats.bounced}
                        sent={stats.sent}
                        tracked={receivesWebhooks}
                        warning={BOUNCE_WARNING}
                        danger={BOUNCE_DANGER}
                        hint="Keep under 2%"
                    />
                    <RateStat
                        label="Complaints"
                        count={stats.complained}
                        sent={stats.sent}
                        tracked={receivesWebhooks}
                        warning={COMPLAINT_WARNING}
                        danger={COMPLAINT_DANGER}
                        hint="Keep under 0.1%"
                    />
                </dl>

                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <WebhookCard provider={provider} />

                    <section className="rounded-xl border bg-card shadow-xs">
                        <div className="flex items-center justify-between border-b px-5 py-3.5">
                            <h2 className="text-sm font-semibold">
                                Sender and limits
                            </h2>
                            <Link
                                href={edit(provider.id)}
                                className="text-13 text-muted-foreground hover:text-foreground hover:underline"
                            >
                                Change
                            </Link>
                        </div>
                        <dl className="space-y-3 px-5 py-4 text-sm">
                            <Detail label="Default sender">
                                {provider.from_email ? (
                                    <>
                                        {provider.from_name && (
                                            <span className="block">
                                                {provider.from_name}
                                            </span>
                                        )}
                                        <span className="text-muted-foreground">
                                            {provider.from_email}
                                        </span>
                                    </>
                                ) : (
                                    <span className="text-muted-foreground">
                                        Not set
                                    </span>
                                )}
                            </Detail>
                            <Detail label="Speed">
                                Up to {provider.max_per_second.toLocaleString()}{' '}
                                emails a second
                            </Detail>
                            <Detail label="Daily limit">
                                {provider.daily_limit
                                    ? `${provider.daily_limit.toLocaleString()} emails`
                                    : 'None'}
                            </Detail>
                        </dl>
                    </section>
                </div>
            </PageBody>

            <Dialog open={testing} onOpenChange={setTesting}>
                <DialogContent>
                    {testing && (
                        <TestForm
                            provider={provider}
                            onClose={() => setTesting(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={removing} onOpenChange={setRemoving}>
                <DialogContent>
                    <DialogTitle>Remove {provider.name}?</DialogTitle>
                    <DialogDescription>
                        Its credentials are deleted. Campaigns and automations
                        that send through it will need another provider.
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(
                                    ProviderController.destroy.url(provider.id),
                                    { onFinish: () => setRemoving(false) },
                                )
                            }
                        >
                            Remove provider
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function WebhookCard({ provider }: { provider: Provider }) {
    const [copied, copy] = useClipboard();
    const verification = provider.type.webhook_verification;

    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3.5">
                <h2 className="text-sm font-semibold">Delivery webhooks</h2>
                {verification !== 'none' && (
                    <span className="text-xs text-muted-foreground">
                        {provider.last_webhook_at
                            ? `Last received ${formatDateTime(provider.last_webhook_at)}`
                            : 'None received yet'}
                    </span>
                )}
            </div>
            <div className="space-y-4 px-5 py-4">
                {verification === 'none' ? (
                    <p className="text-sm text-muted-foreground">
                        {provider.type.label} can't report deliveries back here,
                        so bounces and complaints are not recorded
                        automatically. Check your provider's dashboard now and
                        then, and add bounced addresses to suppressions.
                    </p>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Webhooks tell this workspace when an email is
                            delivered, bounces or is marked as spam, so bad
                            addresses are suppressed automatically.
                        </p>
                        <ol className="space-y-2.5">
                            {webhookSteps[verification]?.map((step, index) => (
                                <li key={index} className="flex gap-3 text-sm">
                                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full border bg-muted/40 text-xs font-medium tabular-nums">
                                        {index + 1}
                                    </span>
                                    <span>{step}</span>
                                </li>
                            ))}
                        </ol>
                        <div className="flex gap-2">
                            <Input
                                readOnly
                                aria-label="Webhook URL"
                                value={provider.webhook_url ?? ''}
                                onFocus={(event) => event.target.select()}
                                className="font-mono text-xs"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => copy(provider.webhook_url ?? '')}
                            >
                                {copied === provider.webhook_url ? (
                                    <Check />
                                ) : (
                                    <Copy />
                                )}
                                {copied === provider.webhook_url
                                    ? 'Copied'
                                    : 'Copy'}
                            </Button>
                        </div>
                        {verification === 'signing_secret' &&
                            !provider.has_webhook_secret && (
                                <Shout
                                    type="warning"
                                    actions={
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="bg-card"
                                            asChild
                                        >
                                            <Link href={edit(provider.id)}>
                                                Add it
                                            </Link>
                                        </Button>
                                    }
                                >
                                    No signing secret saved yet, so webhooks are
                                    rejected.
                                </Shout>
                            )}
                    </>
                )}
            </div>
        </section>
    );
}

function TestForm({
    provider,
    onClose,
}: {
    provider: Provider;
    onClose: () => void;
}) {
    const user = usePage<{ auth: { user: { email: string } } }>().props.auth
        .user;
    const form = useForm({
        from: provider.from_email ?? '',
        to: user.email,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ProviderController.test(provider.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="space-y-1.5">
                <DialogTitle>Send a test</DialogTitle>
                <DialogDescription>
                    A short plain-text email through {provider.name}, to check
                    the credentials work.
                </DialogDescription>
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="test-to">Send to</Label>
                <Input
                    id="test-to"
                    type="email"
                    required
                    autoFocus
                    value={form.data.to}
                    onChange={(event) => form.setData('to', event.target.value)}
                />
                <InputError message={form.errors.to} />
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="test-from">From</Label>
                <Input
                    id="test-from"
                    type="email"
                    required
                    value={form.data.from}
                    onChange={(event) =>
                        form.setData('from', event.target.value)
                    }
                />
                <p className="text-xs text-muted-foreground">
                    Must be an address or domain your provider lets you send
                    from.
                </p>
                <InputError message={form.errors.from} />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button disabled={form.processing}>
                    {form.processing ? <Spinner /> : <Send />}
                    Send test
                </Button>
            </DialogFooter>
        </form>
    );
}

/** A share of the week's sends, coloured when it passes a safe level. */
function RateStat({
    label,
    count,
    sent,
    tracked,
    warning,
    danger,
    hint,
}: {
    label: string;
    count: number;
    sent: number;
    tracked: boolean;
    warning?: number;
    danger?: number;
    hint?: string;
}) {
    if (!tracked) {
        return (
            <StatTile
                label={label}
                value="—"
                hint="Not reported by this provider"
            />
        );
    }

    const rate = sent > 0 ? count / sent : null;

    return (
        <StatTile
            label={label}
            value={rate === null ? '—' : `${(rate * 100).toFixed(1)}%`}
            hint={
                rate === null
                    ? hint
                    : `${count.toLocaleString()} emails${hint ? ` · ${hint.toLowerCase()}` : ''}`
            }
            valueClassName={cn(
                rate !== null &&
                    danger !== undefined &&
                    rate > danger &&
                    'text-red-600 dark:text-red-400',
                rate !== null &&
                    warning !== undefined &&
                    danger !== undefined &&
                    rate > warning &&
                    rate <= danger &&
                    'text-amber-600 dark:text-amber-400',
            )}
        />
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[7rem_1fr] gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </div>
    );
}

ShowProvider.layout = {
    breadcrumbs: [{ title: 'Providers', href: providersIndex() }],
};
