import { Head, useForm, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import type { FormEvent } from 'react';
import ApiTokenController from '@/actions/Gerenuk/Marketing/Http/Controllers/ApiTokenController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { index as apiTokensIndex } from '@/routes/marketing/api-tokens';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import type { Permission } from '@marketing/types';

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
                <div className="space-y-2 rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950/40">
                    <p className="text-sm font-medium">
                        Copy your new token now. It will not be shown again.
                    </p>
                    <div className="flex gap-2">
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
                            {copiedText === plainToken ? <Check /> : <Copy />}
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Send it as a bearer token:{' '}
                        <code>Authorization: Bearer {'<token>'}</code>
                    </p>
                </div>
            )}

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Create a token"
                    description="Tokens act on this workspace only, with the permissions you choose"
                />

                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
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
                        <div className="grid gap-2">
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

                    <div className="grid gap-2">
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
                                                      ...form.data.permissions,
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
                        <InputError message={errors.permissions} />
                    </div>

                    <Button disabled={form.processing}>Create token</Button>
                </form>
            </div>

            <div className="space-y-4">
                <Heading variant="small" title="Tokens" />
                <DataTable
                    headers={['Name', 'Permissions', 'Last used', '']}
                    empty={
                        tokens.length === 0 && (
                            <EmptyRow message="No tokens yet." />
                        )
                    }
                >
                    {tokens.map((token) => (
                        <tr key={token.id}>
                            <Cell>
                                <p className="font-medium">{token.name}</p>
                                {token.expires_at && (
                                    <p className="text-xs text-muted-foreground">
                                        Expires{' '}
                                        {new Date(
                                            token.expires_at,
                                        ).toLocaleDateString()}
                                    </p>
                                )}
                            </Cell>
                            <Cell>
                                <div className="flex flex-wrap gap-1">
                                    {token.permissions.map((permission) => (
                                        <Badge
                                            key={permission}
                                            variant="outline"
                                        >
                                            {permission}
                                        </Badge>
                                    ))}
                                </div>
                            </Cell>
                            <Cell className="text-muted-foreground">
                                {token.last_used_at
                                    ? new Date(
                                          token.last_used_at,
                                      ).toLocaleString()
                                    : 'Never'}
                            </Cell>
                            <Cell className="text-right">
                                <ConfirmDelete
                                    url={ApiTokenController.destroy.url(
                                        token.id,
                                    )}
                                    trigger="Revoke"
                                    title={`Revoke ${token.name}?`}
                                    description="Anything using this token stops working immediately."
                                />
                            </Cell>
                        </tr>
                    ))}
                </DataTable>
            </div>
        </>
    );
}

ApiTokens.layout = {
    breadcrumbs: [{ title: 'API tokens', href: apiTokensIndex() }],
};
