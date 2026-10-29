import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Ellipsis, KeyRound, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ApiTokenController from '@/actions/Gerenuk/Marketing/Http/Controllers/ApiTokenController';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import { index as apiTokensIndex } from '@/routes/marketing/api-tokens';
import {
    PermissionPicker,
    permissionSummary,
} from '@marketing/components/permission-picker';
import { StatusBadge } from '@marketing/components/status-badge';
import { formatDate, formatDateTime } from '@marketing/lib/format';
import type { Permission } from '@marketing/types';

type Token = {
    id: number;
    name: string;
    permissions: Permission[];
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string | null;
};

type PermissionOption = { value: Permission; label: string };

/** The API reads with contacts.view and writes contacts, lists and events with contacts.manage. */
const PRESETS: { label: string; hint: string; permissions: Permission[] }[] = [
    {
        label: 'Read and write',
        hint: 'Add and update contacts, lists and tags, and send events',
        permissions: ['contacts.view', 'contacts.manage'],
    },
    {
        label: 'Read only',
        hint: 'Look up contacts, lists, tags and segments',
        permissions: ['contacts.view'],
    },
];

const EXPIRY_OPTIONS = [
    { value: '', label: 'Never' },
    { value: '30', label: 'In 30 days' },
    { value: '90', label: 'In 90 days' },
    { value: '365', label: 'In a year' },
];

export default function ApiTokens({
    tokens,
    permissions,
}: {
    tokens: Token[];
    permissions: PermissionOption[];
}) {
    // Inertia flash data lives on the page, not in props, and is only present on the response that set it.
    const plainToken = (usePage().flash as { plainToken?: string }).plainToken;
    const [creating, setCreating] = useState(false);
    const [revoking, setRevoking] = useState<Token | null>(null);

    return (
        <>
            <Head title="API tokens" />

            {plainToken && <NewToken token={plainToken} />}

            <section className="rounded-xl border bg-card shadow-xs">
                <header className="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4 sm:px-6">
                    <div className="space-y-1">
                        <h2 className="text-base font-semibold">API tokens</h2>
                        <p className="text-sm text-muted-foreground">
                            Let your website or app add contacts and send
                            events. Each token works on this workspace only.
                        </p>
                    </div>
                    <Button size="sm" onClick={() => setCreating(true)}>
                        <Plus />
                        New token
                    </Button>
                </header>

                {tokens.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 py-14 text-center">
                        <span className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
                            <KeyRound className="size-5" />
                        </span>
                        <div className="space-y-1">
                            <p className="font-medium">No API tokens</p>
                            <p className="max-w-md text-sm text-muted-foreground">
                                Create a token for each place that talks to the
                                API, such as a sign-up form, so you can revoke
                                one without breaking the others.
                            </p>
                        </div>
                    </div>
                ) : (
                    <div className="divide-y">
                        {tokens.map((token) => {
                            const expired =
                                token.expires_at !== null &&
                                new Date(token.expires_at) < new Date();

                            return (
                                <div
                                    key={token.id}
                                    className="flex items-start gap-4 px-5 py-4 sm:px-6"
                                >
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/40 text-muted-foreground">
                                        <KeyRound className="size-4" />
                                    </span>
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <p className="flex flex-wrap items-center gap-2 font-medium">
                                            {token.name}
                                            {expired && (
                                                <StatusBadge tone="neutral">
                                                    Expired
                                                </StatusBadge>
                                            )}
                                        </p>
                                        <p className="text-13 text-muted-foreground">
                                            {permissionSummary(
                                                token.permissions,
                                            ).join(' · ') || 'No permissions'}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {token.last_used_at
                                                ? `Last used ${formatDateTime(token.last_used_at)}`
                                                : 'Never used'}
                                            {token.expires_at &&
                                                ` · ${expired ? 'expired' : 'expires'} ${formatDate(token.expires_at)}`}
                                            {token.created_at &&
                                                ` · created ${formatDate(token.created_at)}`}
                                        </p>
                                    </div>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="-mr-2 size-8 shrink-0 text-muted-foreground"
                                                aria-label={`Actions for ${token.name}`}
                                            >
                                                <Ellipsis />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() =>
                                                    setRevoking(token)
                                                }
                                            >
                                                <Trash2 />
                                                Revoke
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            );
                        })}
                    </div>
                )}
            </section>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[calc(100svh-2rem)] overflow-y-auto sm:max-w-2xl">
                    {creating && (
                        <CreateForm
                            permissions={permissions}
                            onDone={() => setCreating(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
            >
                <DialogContent>
                    <DialogTitle>Revoke {revoking?.name}?</DialogTitle>
                    <DialogDescription>
                        Anything using this token stops working straight away.
                        This can't be undone.
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                revoking &&
                                router.delete(
                                    ApiTokenController.destroy.url(revoking.id),
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setRevoking(null),
                                    },
                                )
                            }
                        >
                            Revoke token
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/**
 * The token just created, shown once, with how to use it.
 */
function NewToken({ token }: { token: string }) {
    const [copied, copy] = useClipboard();

    return (
        <div className="space-y-3 rounded-xl border border-emerald-300/60 bg-emerald-50/60 p-5 dark:border-emerald-400/20 dark:bg-emerald-400/5">
            <div className="space-y-0.5">
                <p className="font-medium">Copy your new token now</p>
                <p className="text-13 text-muted-foreground">
                    For your security it won't be shown again. If you lose it,
                    revoke it and create another.
                </p>
            </div>
            <div className="flex gap-2">
                <Input
                    readOnly
                    aria-label="New API token"
                    value={token}
                    onFocus={(event) => event.target.select()}
                    className="bg-card font-mono text-xs"
                />
                <Button
                    type="button"
                    variant="outline"
                    className="bg-card"
                    onClick={() => copy(token)}
                >
                    {copied === token ? <Check /> : <Copy />}
                    {copied === token ? 'Copied' : 'Copy'}
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">
                Send it with each request as{' '}
                <code className="rounded bg-card px-1 py-0.5 font-mono">
                    Authorization: Bearer {'<token>'}
                </code>
            </p>
        </div>
    );
}

function CreateForm({
    permissions,
    onDone,
}: {
    permissions: PermissionOption[];
    onDone: () => void;
}) {
    const available = (list: Permission[]) =>
        list.filter((permission) =>
            permissions.some((option) => option.value === permission),
        );
    const form = useForm<{
        name: string;
        permissions: Permission[];
        expires_in_days: string;
    }>({
        name: '',
        permissions: available(PRESETS[0].permissions),
        expires_in_days: '',
    });
    const sameAs = (list: Permission[]) =>
        available(list).length === form.data.permissions.length &&
        available(list).every((permission) =>
            form.data.permissions.includes(permission),
        );

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
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-5">
            <div className="space-y-1.5">
                <DialogTitle>New API token</DialogTitle>
                <DialogDescription>
                    Give it only what it needs. You see the token once, right
                    after creating it.
                </DialogDescription>
            </div>
            <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
                <div className="grid content-start gap-2">
                    <Label htmlFor="token-name">Name</Label>
                    <Input
                        id="token-name"
                        required
                        autoFocus
                        placeholder="Website sign-up form"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                    <InputError message={form.errors.name} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="token-expiry">Expires</Label>
                    <NativeSelect
                        id="token-expiry"
                        value={form.data.expires_in_days}
                        onChange={(event) =>
                            form.setData('expires_in_days', event.target.value)
                        }
                    >
                        {EXPIRY_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={form.errors.expires_in_days} />
                </div>
            </div>
            <div className="grid content-start gap-2">
                <Label>Access</Label>
                <div className="grid gap-2 sm:grid-cols-2">
                    {PRESETS.map((preset) => (
                        <button
                            key={preset.label}
                            type="button"
                            aria-pressed={sameAs(preset.permissions)}
                            onClick={() =>
                                form.setData(
                                    'permissions',
                                    available(preset.permissions),
                                )
                            }
                            className={cn(
                                'rounded-lg border bg-card px-3 py-2.5 text-left transition-colors outline-none hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                sameAs(preset.permissions) &&
                                    'border-foreground ring-1 ring-foreground',
                            )}
                        >
                            <span className="block text-sm font-medium">
                                {preset.label}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {preset.hint}
                            </span>
                        </button>
                    ))}
                </div>
                <details
                    className="group text-13"
                    open={!PRESETS.some((preset) => sameAs(preset.permissions))}
                >
                    <summary className="w-fit cursor-pointer text-muted-foreground hover:text-foreground">
                        Choose permissions one by one
                    </summary>
                    <div className="mt-2">
                        <PermissionPicker
                            options={permissions}
                            value={form.data.permissions}
                            onChange={(value) =>
                                form.setData('permissions', value)
                            }
                        />
                    </div>
                </details>
                <InputError message={form.errors.permissions} />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button
                    disabled={
                        form.processing || form.data.permissions.length === 0
                    }
                >
                    {form.processing && <Spinner />}
                    Create token
                </Button>
            </DialogFooter>
        </form>
    );
}

ApiTokens.layout = {
    breadcrumbs: [{ title: 'API tokens', href: apiTokensIndex() }],
};
