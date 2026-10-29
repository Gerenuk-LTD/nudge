import { Head, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound } from 'lucide-react';
import type { FormEvent } from 'react';
import ApiTokenController from '@/actions/Gerenuk/Marketing/Http/Controllers/ApiTokenController';
import InputError from '@/components/input-error';
import { SettingsSection } from '@marketing/components/settings-section';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { index as apiTokensIndex } from '@/routes/marketing/api-tokens';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import {
    SettingsList,
    SettingsListItem,
} from '@marketing/components/settings-list';
import type { Permission } from '@marketing/types';
import { formatDate, formatDateTime } from '@marketing/lib/format';

type Token = {
    id: number;
    name: string;
    permissions: Permission[];
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string | null;
};

export default function ApiTokens({
    tokens,
    permissions,
}: {
    tokens: Token[];
    permissions: { value: Permission; label: string }[];
}) {
    // Inertia flash data lives on the page, not in props, and is only present on the response that set it.
    const plainToken = (usePage().flash as { plainToken?: string }).plainToken;
    const [copiedText, copy] = useClipboard();

    const form = useForm<{
        name: string;
        permissions: Permission[];
        expires_in_days: string;
    }>({
        name: '',
        permissions: ['contacts.view', 'contacts.manage'].filter((permission) =>
            permissions.some((option) => option.value === permission),
        ) as Permission[],
        expires_in_days: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            expires_in_days:
                data.expires_in_days === ''
                    ? null
                    : Number(data.expires_in_days),
        }));
        form.submit(ApiTokenController.store(), {
            preserveScroll: true,
            onSuccess: () => form.reset('name'),
        });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <>
            <Head title="API tokens" />

            {plainToken && (
                <Alert>
                    <KeyRound />
                    <AlertTitle>Copy your new token now</AlertTitle>
                    <AlertDescription className="w-full gap-2">
                        <p>It won't be shown again.</p>
                        <div className="flex w-full gap-2">
                            <Input
                                readOnly
                                value={plainToken}
                                className="font-mono text-xs"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Copy token"
                                onClick={() => copy(plainToken)}
                            >
                                {copiedText === plainToken ? (
                                    <Check />
                                ) : (
                                    <Copy />
                                )}
                            </Button>
                        </div>
                        <p className="text-xs">
                            Send it as a bearer token:{' '}
                            <code>Authorization: Bearer {'<token>'}</code>
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit}>
                <SettingsSection
                    title="Create a token"
                    description="Tokens act on this workspace only, with the permissions you choose."
                    actions={
                        <Button size="sm" disabled={form.processing}>
                            Create token
                        </Button>
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="token-name">Name</Label>
                            <Input
                                id="token-name"
                                required
                                placeholder="Website signup form"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="token-expiry">
                                Expires after (days)
                            </Label>
                            <Input
                                id="token-expiry"
                                type="number"
                                min={1}
                                placeholder="Never"
                                value={form.data.expires_in_days}
                                onChange={(event) =>
                                    form.setData(
                                        'expires_in_days',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={errors.expires_in_days} />
                        </div>
                    </div>

                    <div className="grid content-start gap-2">
                        <Label>Permissions</Label>
                        <div className="grid gap-2 rounded-md border p-3 sm:grid-cols-2">
                            {permissions.map((permission) => (
                                <label
                                    key={permission.value}
                                    className="flex items-start gap-2 text-sm"
                                >
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={form.data.permissions.includes(
                                            permission.value,
                                        )}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'permissions',
                                                checked === true
                                                    ? [
                                                          ...form.data
                                                              .permissions,
                                                          permission.value,
                                                      ]
                                                    : form.data.permissions.filter(
                                                          (p) =>
                                                              p !==
                                                              permission.value,
                                                      ),
                                            )
                                        }
                                    />
                                    {permission.label}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.permissions} />
                    </div>
                </SettingsSection>
            </form>

            <SettingsList
                title="Tokens"
                description="Revoke a token as soon as nothing needs it."
                empty="No tokens yet."
            >
                {tokens.map((token) => (
                    <SettingsListItem
                        key={token.id}
                        leading={
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-background text-muted-foreground">
                                <KeyRound className="size-4" />
                            </span>
                        }
                        title={token.name}
                        detail={[
                            token.last_used_at
                                ? `Last used ${formatDateTime(token.last_used_at)}`
                                : 'Never used',
                            token.expires_at &&
                                `expires ${formatDate(token.expires_at)}`,
                            token.permissions.join(', '),
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    >
                        <ConfirmDelete
                            url={ApiTokenController.destroy.url(token.id)}
                            trigger="Revoke"
                            title={`Revoke ${token.name}?`}
                            description="Anything using this token stops working immediately."
                        />
                    </SettingsListItem>
                ))}
            </SettingsList>
        </>
    );
}

ApiTokens.layout = {
    breadcrumbs: [{ title: 'API tokens', href: apiTokensIndex() }],
};
