import { Head, useForm } from '@inertiajs/react';
import { CircleCheck, CircleMinus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ProviderController from '@/actions/Gerenuk/Nudge/Http/Controllers/ProviderController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import {
    index as providersIndex,
    show as showProvider,
} from '@/routes/nudge/providers';
import { NativeSelect } from '@/components/ui/native-select';
import { EditorBar, Stepper } from '@nudge/components/editor-bar';
import type { SaveState } from '@nudge/components/editor-bar';
import { PageBody } from '@nudge/components/page-header';
import { ProviderMark } from '@nudge/components/provider-mark';
import { SettingsSection } from '@nudge/components/settings-section';
import {
    useSaveShortcut,
    useUnsavedChangesGuard,
} from '@nudge/hooks/use-editor';
import type { ProviderTypeDefinition } from '@nudge/types';

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
    // A new connection starts by picking the service; going back to it keeps what was typed for another type.
    const [step, setStep] = useState<'choose' | 'details'>(
        provider ? 'details' : 'choose',
    );

    const type = types.find((candidate) => candidate.value === form.data.type);
    const errors = form.errors as Record<string, string | undefined>;

    const chooseType = (value: string) => {
        const chosen = types.find((candidate) => candidate.value === value);

        form.setData((data) => ({
            ...data,
            type: value,
            name:
                data.name === '' ||
                types.some((candidate) => candidate.label === data.name)
                    ? (chosen?.label ?? '')
                    : data.name,
            credentials:
                data.type === value
                    ? data.credentials
                    : defaultCredentials(chosen),
        }));
        setStep('details');
    };

    const save = () => {
        if (!type) {
            return;
        }

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

    const submit = (event: FormEvent) => {
        event.preventDefault();
        save();
    };

    useSaveShortcut(save, type !== undefined);
    useUnsavedChangesGuard(form.isDirty && !form.processing);

    const saveState: SaveState = form.processing
        ? 'saving'
        : form.hasErrors
          ? 'error'
          : form.isDirty || !provider
            ? 'unsaved'
            : 'saved';

    return (
        <>
            <Head
                title={
                    provider ? `Edit ${provider.name}` : 'Connect a provider'
                }
            />

            <PageBody>
                <EditorBar
                    back={
                        provider
                            ? {
                                  href: showProvider(provider.id),
                                  label: provider.name,
                              }
                            : { href: providersIndex(), label: 'providers' }
                    }
                    title={type ? form.data.name : 'Connect a provider'}
                    titlePlaceholder="Name this connection"
                    titleError={errors.name}
                    onTitleChange={
                        type ? (name) => form.setData('name', name) : undefined
                    }
                    saveState={type ? saveState : undefined}
                    meta={
                        type
                            ? type.label
                            : 'Pick the service you send email with'
                    }
                    stepper={
                        !provider && (
                            <Stepper
                                current={step}
                                onSelect={(key) =>
                                    (key === 'choose' || type) &&
                                    setStep(key as 'choose' | 'details')
                                }
                                steps={[
                                    {
                                        key: 'choose',
                                        label: 'Choose provider',
                                        state: type ? 'complete' : undefined,
                                    },
                                    { key: 'details', label: 'Connect' },
                                ]}
                            />
                        )
                    }
                    actions={
                        type &&
                        step === 'details' && (
                            <Button
                                type="submit"
                                form="provider-form"
                                disabled={form.processing}
                            >
                                {form.processing && <Spinner />}
                                {provider ? 'Save changes' : 'Connect'}
                            </Button>
                        )
                    }
                />

                {step === 'choose' ? (
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Use the account you already have. Credentials are
                            encrypted and only used to send this workspace's
                            email.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {types.map((candidate) => {
                                const tracked =
                                    candidate.webhook_verification !== 'none';

                                return (
                                    <button
                                        key={candidate.value}
                                        type="button"
                                        onClick={() =>
                                            chooseType(candidate.value)
                                        }
                                        className={cn(
                                            'flex items-center gap-3 rounded-xl border bg-card p-4 text-left shadow-xs transition-colors outline-none hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                            form.data.type ===
                                                candidate.value &&
                                                'border-foreground ring-1 ring-foreground',
                                        )}
                                    >
                                        <ProviderMark label={candidate.label} />
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-medium">
                                                {candidate.label}
                                            </span>
                                            <span
                                                className={cn(
                                                    'flex items-center gap-1 text-xs',
                                                    tracked
                                                        ? 'text-emerald-700 dark:text-emerald-400'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {tracked ? (
                                                    <CircleCheck className="size-3" />
                                                ) : (
                                                    <CircleMinus className="size-3" />
                                                )}
                                                {tracked
                                                    ? 'Tracks bounces and complaints'
                                                    : 'No bounce tracking'}
                                            </span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <InputError message={errors.type} />
                    </div>
                ) : (
                    type && (
                        <form
                            id="provider-form"
                            onSubmit={submit}
                            className="space-y-6"
                        >
                            <SettingsSection
                                title={`${type.label} credentials`}
                                description={
                                    provider
                                        ? 'Secrets are never shown again. Leave them blank to keep the saved ones.'
                                        : "Encrypted, and only used to send this workspace's email."
                                }
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {type.fields.map((field) => (
                                        <CredentialInput
                                            key={field.key}
                                            field={field}
                                            saved={provider !== null}
                                            value={
                                                form.data.credentials[
                                                    field.key
                                                ] ?? ''
                                            }
                                            onChange={(value) =>
                                                form.setData('credentials', {
                                                    ...form.data.credentials,
                                                    [field.key]: value,
                                                })
                                            }
                                            error={
                                                errors[
                                                    `credentials.${field.key}`
                                                ]
                                            }
                                        />
                                    ))}
                                </div>
                            </SettingsSection>

                            {type.webhook_verification === 'signing_secret' && (
                                <SettingsSection
                                    title="Delivery webhooks"
                                    description={
                                        provider
                                            ? undefined
                                            : 'You get the webhook URL after connecting. You can add the secret then.'
                                    }
                                >
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid content-start gap-2">
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
                                                        : 'Optional for now'
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
                                                settings. Without it, delivery
                                                events are rejected.
                                            </p>
                                            <InputError
                                                message={errors.webhook_secret}
                                            />
                                        </div>
                                    </div>
                                </SettingsSection>
                            )}

                            <SettingsSection
                                title="Default sender"
                                description="New campaigns start with these. Each campaign can change them."
                            >
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
                            </SettingsSection>

                            <SettingsSection
                                title="Sending limits"
                                description="Match these to your plan with the provider. Sending pauses at the daily limit and carries on the next day."
                            >
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
                            </SettingsSection>
                        </form>
                    )
                )}
            </PageBody>
        </>
    );
}

function CredentialInput({
    field,
    saved,
    value,
    onChange,
    error,
}: {
    field: ProviderTypeDefinition['fields'][number];
    saved: boolean;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    const id = `credential-${field.key}`;

    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>
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
                    id={id}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                >
                    {field.options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </NativeSelect>
            ) : (
                <Input
                    id={id}
                    type={field.secret ? 'password' : 'text'}
                    autoComplete="off"
                    placeholder={
                        saved && field.secret
                            ? 'Saved. Leave blank to keep.'
                            : undefined
                    }
                    className={cn(field.secret && 'font-mono')}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                />
            )}
            {field.help && (
                <p className="text-xs text-muted-foreground">{field.help}</p>
            )}
            <InputError message={error} />
        </div>
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
        <div className="grid content-start gap-2">
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
