import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ProviderController from '@/actions/Gerenuk/Marketing/Http/Controllers/ProviderController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index as providersIndex } from '@/routes/marketing/providers';
import { NativeSelect } from '@marketing/components/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import type { ProviderTypeDefinition } from '@marketing/types';

type ExistingProvider = {
    id: number;
    name: string;
    type: string;
    credentials: Record<string, string>;
    from_name: string | null;
    from_email: string | null;
    reply_to: string | null;
    max_per_second: number;
    daily_limit: number | null;
    has_webhook_secret: boolean;
};

type FormData = {
    name: string;
    type: string;
    credentials: Record<string, string>;
    webhook_secret: string;
    from_name: string;
    from_email: string;
    reply_to: string;
    max_per_second: string;
    daily_limit: string;
};

function defaultCredentials(
    type: ProviderTypeDefinition | undefined,
): Record<string, string> {
    return Object.fromEntries(
        (type?.fields ?? []).map((field) => [field.key, field.default ?? '']),
    );
}

export default function ProviderForm({
    provider,
    types,
}: {
    provider: ExistingProvider | null;
    types: ProviderTypeDefinition[];
}) {
    const form = useForm<FormData>({
        name: provider?.name ?? '',
        type: provider?.type ?? '',
        credentials: provider?.credentials ?? {},
        webhook_secret: '',
        from_name: provider?.from_name ?? '',
        from_email: provider?.from_email ?? '',
        reply_to: provider?.reply_to ?? '',
        max_per_second: String(provider?.max_per_second ?? 10),
        daily_limit: provider?.daily_limit ? String(provider.daily_limit) : '',
    });

    const type = types.find((candidate) => candidate.value === form.data.type);
    const errors = form.errors as Record<string, string | undefined>;

    const chooseType = (value: string) => {
        const chosen = types.find((candidate) => candidate.value === value);

        form.setData((data) => ({
            ...data,
            type: value,
            name: data.name || (chosen?.label ?? ''),
            credentials: defaultCredentials(chosen),
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => {
            const { type: chosenType, ...rest } = data;

            return {
                ...(provider ? rest : { ...rest, type: chosenType }),
                daily_limit:
                    data.daily_limit === '' ? null : Number(data.daily_limit),
                max_per_second: Number(data.max_per_second),
            };
        });

        form.submit(
            provider
                ? ProviderController.update(provider.id)
                : ProviderController.store(),
        );
    };

    return (
        <>
            <Head
                title={
                    provider ? `Edit ${provider.name}` : 'Connect a provider'
                }
            />

            <PageBody>
                <PageHeader
                    title={
                        provider
                            ? `Edit ${provider.name}`
                            : 'Connect a provider'
                    }
                    description="Credentials are encrypted and only used to send this workspace's email."
                />

                <form onSubmit={submit} className="max-w-3xl space-y-8">
                    {!provider && (
                        <div className="space-y-3">
                            <Label>Provider</Label>
                            <div className="grid gap-2 sm:grid-cols-3">
                                {types.map((candidate) => (
                                    <button
                                        key={candidate.value}
                                        type="button"
                                        onClick={() =>
                                            chooseType(candidate.value)
                                        }
                                        className={cn(
                                            'rounded-lg border px-3 py-2.5 text-left text-sm transition-colors hover:bg-muted/50',
                                            form.data.type ===
                                                candidate.value &&
                                                'border-primary bg-muted',
                                        )}
                                    >
                                        <span className="font-medium">
                                            {candidate.label}
                                        </span>
                                        {candidate.webhook_verification ===
                                            'none' && (
                                            <span className="block text-xs text-muted-foreground">
                                                No bounce webhooks
                                            </span>
                                        )}
                                    </button>
                                ))}
                            </div>
                            <InputError message={errors.type} />
                        </div>
                    )}

                    {type && (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    required
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                />
                                <InputError message={errors.name} />
                            </div>

                            <fieldset className="space-y-4">
                                <legend className="mb-2 text-sm font-medium">
                                    {type.label} credentials
                                </legend>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {type.fields.map((field) => (
                                        <div
                                            key={field.key}
                                            className="grid gap-2"
                                        >
                                            <Label
                                                htmlFor={`credential-${field.key}`}
                                            >
                                                {field.label}
                                                {!field.required && (
                                                    <span className="font-normal text-muted-foreground">
                                                        {' '}
                                                        (optional)
                                                    </span>
                                                )}
                                            </Label>
                                            {field.options.length > 0 ? (
                                                <NativeSelect
                                                    id={`credential-${field.key}`}
                                                    value={
                                                        form.data.credentials[
                                                            field.key
                                                        ] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'credentials',
                                                            {
                                                                ...form.data
                                                                    .credentials,
                                                                [field.key]:
                                                                    event.target
                                                                        .value,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {field.options.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </NativeSelect>
                                            ) : (
                                                <Input
                                                    id={`credential-${field.key}`}
                                                    type={
                                                        field.secret
                                                            ? 'password'
                                                            : 'text'
                                                    }
                                                    autoComplete="off"
                                                    placeholder={
                                                        provider && field.secret
                                                            ? 'Saved. Leave blank to keep.'
                                                            : undefined
                                                    }
                                                    value={
                                                        form.data.credentials[
                                                            field.key
                                                        ] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'credentials',
                                                            {
                                                                ...form.data
                                                                    .credentials,
                                                                [field.key]:
                                                                    event.target
                                                                        .value,
                                                            },
                                                        )
                                                    }
                                                />
                                            )}
                                            {field.help && (
                                                <p className="text-xs text-muted-foreground">
                                                    {field.help}
                                                </p>
                                            )}
                                            <InputError
                                                message={
                                                    errors[
                                                        `credentials.${field.key}`
                                                    ]
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                            </fieldset>

                            {type.webhook_verification === 'signing_secret' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="webhook_secret">
                                        {type.webhook_secret_label}
                                    </Label>
                                    <Input
                                        id="webhook_secret"
                                        type="password"
                                        autoComplete="off"
                                        placeholder={
                                            provider?.has_webhook_secret
                                                ? 'Saved. Leave blank to keep.'
                                                : 'You can add this after creating the webhook'
                                        }
                                        value={form.data.webhook_secret}
                                        onChange={(event) =>
                                            form.setData(
                                                'webhook_secret',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Found in {type.label}'s webhook
                                        settings. Without it, delivery events
                                        are rejected.
                                    </p>
                                    <InputError
                                        message={errors.webhook_secret}
                                    />
                                </div>
                            )}

                            <fieldset className="space-y-4">
                                <legend className="mb-2 text-sm font-medium">
                                    Default sender
                                </legend>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <TextField
                                        id="from_name"
                                        label="From name"
                                        value={form.data.from_name}
                                        onChange={(value) =>
                                            form.setData('from_name', value)
                                        }
                                        error={errors.from_name}
                                    />
                                    <TextField
                                        id="from_email"
                                        label="From email"
                                        type="email"
                                        value={form.data.from_email}
                                        onChange={(value) =>
                                            form.setData('from_email', value)
                                        }
                                        error={errors.from_email}
                                    />
                                    <TextField
                                        id="reply_to"
                                        label="Reply-to"
                                        type="email"
                                        value={form.data.reply_to}
                                        onChange={(value) =>
                                            form.setData('reply_to', value)
                                        }
                                        error={errors.reply_to}
                                    />
                                </div>
                            </fieldset>

                            <fieldset className="space-y-4">
                                <legend className="mb-2 text-sm font-medium">
                                    Limits
                                </legend>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <TextField
                                        id="max_per_second"
                                        label="Emails per second"
                                        type="number"
                                        value={form.data.max_per_second}
                                        onChange={(value) =>
                                            form.setData(
                                                'max_per_second',
                                                value,
                                            )
                                        }
                                        error={errors.max_per_second}
                                    />
                                    <TextField
                                        id="daily_limit"
                                        label="Daily limit (optional)"
                                        type="number"
                                        value={form.data.daily_limit}
                                        onChange={(value) =>
                                            form.setData('daily_limit', value)
                                        }
                                        error={errors.daily_limit}
                                    />
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Match these to your provider plan. Sending
                                    pauses at the daily limit and resumes the
                                    next day.
                                </p>
                            </fieldset>

                            <div className="flex gap-2">
                                <Button disabled={form.processing}>
                                    {form.processing && <Spinner />}
                                    {provider ? 'Save' : 'Connect'}
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={providersIndex()}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </form>
            </PageBody>
        </>
    );
}

function TextField({
    id,
    label,
    value,
    onChange,
    error,
    type = 'text',
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    type?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type={type}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
            <InputError message={error} />
        </div>
    );
}

ProviderForm.layout = {
    breadcrumbs: [{ title: 'Providers', href: providersIndex() }],
};
