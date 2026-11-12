import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Ellipsis, Mail, Trash2, UserMinus, UserPlus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import MemberController from '@/actions/Gerenuk/Marketing/Http/Controllers/MemberController';
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
import { index as membersIndex } from '@/routes/marketing/members';
import { ContactAvatar } from '@marketing/components/contact-fields';
import {
    SettingsList,
    SettingsListItem,
} from '@marketing/components/settings-list';
import { StatusBadge } from '@marketing/components/status-badge';
import type { Option } from '@marketing/types';

type Member = {
    id: number;
    name: string;
    email: string;
    role_id: number;
    is_you: boolean;
};
type Invitation = { id: number; email: string; role: string; expired: boolean };

/** Something to confirm before it happens: removing a member or revoking an invitation. */
type Confirming =
    | { kind: 'member'; member: Member }
    | { kind: 'invitation'; invitation: Invitation };

export default function Members({
    members,
    invitations,
    roles,
}: {
    members: Member[];
    invitations: Invitation[];
    roles: Option[];
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [inviting, setInviting] = useState(false);
    const [confirming, setConfirming] = useState<Confirming | null>(null);

    return (
        <>
            <Head title="Members" />

            <InputError message={errors.member} />

            <SettingsList
                title="Members"
                description={`${members.length} ${members.length === 1 ? 'person has' : 'people have'} access to this workspace${invitations.length > 0 ? `, and ${invitations.length} ${invitations.length === 1 ? 'invitation is' : 'invitations are'} waiting` : ''}.`}
                actions={
                    <Button size="sm" onClick={() => setInviting(true)}>
                        <UserPlus />
                        Invite people
                    </Button>
                }
            >
                {members.map((member) => (
                    <SettingsListItem
                        key={member.id}
                        leading={
                            <ContactAvatar
                                name={member.name}
                                email={member.email}
                            />
                        }
                        title={
                            <>
                                {member.name}
                                {member.is_you && (
                                    <StatusBadge tone="neutral">
                                        You
                                    </StatusBadge>
                                )}
                            </>
                        }
                        detail={member.email}
                    >
                        <NativeSelect
                            aria-label={`Role for ${member.name}`}
                            value={member.role_id}
                            size="sm"
                            className="w-36"
                            onChange={(event) =>
                                router.put(
                                    MemberController.update.url(member.id),
                                    { role_id: Number(event.target.value) },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {roles.map((role) => (
                                <option key={role.id} value={role.id}>
                                    {role.name}
                                </option>
                            ))}
                        </NativeSelect>
                        <RowMenu
                            label={`Actions for ${member.name}`}
                            disabled={member.is_you}
                            action={
                                member.is_you
                                    ? 'You can’t remove yourself'
                                    : 'Remove from workspace'
                            }
                            icon={<UserMinus />}
                            onSelect={() =>
                                setConfirming({ kind: 'member', member })
                            }
                        />
                    </SettingsListItem>
                ))}
                {invitations.map((invitation) => (
                    <SettingsListItem
                        key={`invitation-${invitation.id}`}
                        leading={
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-full border border-dashed text-muted-foreground">
                                <Mail className="size-3.5" />
                            </span>
                        }
                        title={
                            <>
                                <span className="min-w-0 truncate text-muted-foreground">
                                    {invitation.email}
                                </span>
                                <StatusBadge
                                    tone={
                                        invitation.expired
                                            ? 'neutral'
                                            : 'warning'
                                    }
                                >
                                    {invitation.expired
                                        ? 'Invitation expired'
                                        : 'Invited'}
                                </StatusBadge>
                            </>
                        }
                        detail={`Will join as ${invitation.role}`}
                    >
                        <RowMenu
                            label={`Actions for ${invitation.email}`}
                            action="Revoke invitation"
                            icon={<Trash2 />}
                            onSelect={() =>
                                setConfirming({
                                    kind: 'invitation',
                                    invitation,
                                })
                            }
                        />
                    </SettingsListItem>
                ))}
            </SettingsList>

            <Dialog open={inviting} onOpenChange={setInviting}>
                <DialogContent>
                    {inviting && (
                        <InviteForm
                            roles={roles}
                            onDone={() => setInviting(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        {confirming?.kind === 'member'
                            ? `Remove ${confirming.member.name}?`
                            : 'Revoke this invitation?'}
                    </DialogTitle>
                    <DialogDescription>
                        {confirming?.kind === 'member'
                            ? 'They lose access to this workspace straight away. Their campaigns and other work stay.'
                            : `The link sent to ${confirming?.invitation.email} stops working.`}
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                confirming &&
                                router.delete(
                                    confirming.kind === 'member'
                                        ? MemberController.destroy.url(
                                              confirming.member.id,
                                          )
                                        : MemberController.revokeInvitation.url(
                                              confirming.invitation.id,
                                          ),
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setConfirming(null),
                                    },
                                )
                            }
                        >
                            {confirming?.kind === 'member'
                                ? 'Remove member'
                                : 'Revoke invitation'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function InviteForm({
    roles,
    onDone,
}: {
    roles: Option[];
    onDone: () => void;
}) {
    const form = useForm({
        email: '',
        role_id: String(
            roles.find((role) => role.name === 'Editor')?.id ??
                roles[0]?.id ??
                '',
        ),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, role_id: Number(data.role_id) }));
        form.submit(MemberController.invite(), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="space-y-1.5">
                <DialogTitle>Invite people</DialogTitle>
                <DialogDescription>
                    They get an email with a link to join this workspace.
                </DialogDescription>
            </div>
            <div className="grid gap-4 sm:grid-cols-[1fr_10rem]">
                <div className="grid content-start gap-2">
                    <Label htmlFor="invite-email">Email address</Label>
                    <Input
                        id="invite-email"
                        type="email"
                        required
                        autoFocus
                        placeholder="name@company.com"
                        value={form.data.email}
                        onChange={(event) =>
                            form.setData('email', event.target.value)
                        }
                    />
                    <InputError message={form.errors.email} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="invite-role">Role</Label>
                    <NativeSelect
                        id="invite-role"
                        value={form.data.role_id}
                        onChange={(event) =>
                            form.setData('role_id', event.target.value)
                        }
                    >
                        {roles.map((role) => (
                            <option key={role.id} value={role.id}>
                                {role.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={form.errors.role_id} />
                </div>
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Send invitation
                </Button>
            </DialogFooter>
        </form>
    );
}

function RowMenu({
    label,
    action,
    icon,
    disabled = false,
    onSelect,
}: {
    label: string;
    action: string;
    icon: ReactNode;
    disabled?: boolean;
    onSelect: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground"
                    aria-label={label}
                >
                    <Ellipsis />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    variant="destructive"
                    disabled={disabled}
                    onSelect={onSelect}
                >
                    {icon}
                    {action}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

Members.layout = {
    breadcrumbs: [{ title: 'Members', href: membersIndex() }],
};
