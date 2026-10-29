import { Form, Head, router, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import MemberController from '@/actions/Gerenuk/Marketing/Http/Controllers/MemberController';
import InputError from '@/components/input-error';
import { SettingsSection } from '@marketing/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as membersIndex } from '@/routes/marketing/members';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import {
    SettingsList,
    SettingsListItem,
} from '@marketing/components/settings-list';
import { StatusBadge } from '@marketing/components/status-badge';
import { NativeSelect } from '@/components/ui/native-select';
import type { Option } from '@marketing/types';

type Member = { id: number; name: string; email: string; role_id: number };
type Invitation = { id: number; email: string; role: string; expired: boolean };

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

    return (
        <>
            <Head title="Members" />

            <Form
                {...MemberController.invite.form()}
                resetOnSuccess={['email']}
                options={{ preserveScroll: true }}
            >
                {({ processing, recentlySuccessful, errors: formErrors }) => (
                    <SettingsSection
                        title="Invite someone"
                        description="They get an email with a link to join this workspace."
                        hint={recentlySuccessful && 'Invitation sent.'}
                        actions={
                            <Button size="sm" disabled={processing}>
                                Send invite
                            </Button>
                        }
                    >
                        <div className="flex flex-wrap items-start gap-2">
                            <div className="min-w-56 flex-1 space-y-1">
                                <Input
                                    name="email"
                                    type="email"
                                    placeholder="name@company.com"
                                    aria-label="Email address"
                                    required
                                />
                                <InputError message={formErrors.email} />
                            </div>
                            <NativeSelect
                                name="role_id"
                                aria-label="Role"
                                defaultValue={
                                    roles.find((role) => role.name === 'Editor')
                                        ?.id
                                }
                            >
                                {roles.map((role) => (
                                    <option key={role.id} value={role.id}>
                                        {role.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </SettingsSection>
                )}
            </Form>

            <InputError message={errors.member} />

            <SettingsList
                title="Members"
                description={`${members.length} ${members.length === 1 ? 'person has' : 'people have'} access to this workspace.`}
            >
                {members.map((member) => (
                    <SettingsListItem
                        key={member.id}
                        leading={<Initials name={member.name} />}
                        title={member.name}
                        detail={member.email}
                    >
                        <NativeSelect
                            aria-label={`Role for ${member.name}`}
                            value={member.role_id}
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
                        <ConfirmDelete
                            url={MemberController.destroy.url(member.id)}
                            trigger="Remove"
                            title={`Remove ${member.name}?`}
                            description="They lose access to this workspace straight away."
                        />
                    </SettingsListItem>
                ))}
            </SettingsList>

            {invitations.length > 0 && (
                <SettingsList
                    title="Pending invitations"
                    description="Waiting for these people to accept."
                >
                    {invitations.map((invitation) => (
                        <SettingsListItem
                            key={invitation.id}
                            leading={
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-full border border-dashed text-muted-foreground">
                                    <Mail className="size-4" />
                                </span>
                            }
                            title={
                                <>
                                    {invitation.email}
                                    {invitation.expired && (
                                        <StatusBadge tone="neutral">
                                            Expired
                                        </StatusBadge>
                                    )}
                                </>
                            }
                            detail={`Invited as ${invitation.role}`}
                        >
                            <ConfirmDelete
                                url={MemberController.revokeInvitation.url(
                                    invitation.id,
                                )}
                                trigger="Revoke"
                                title="Revoke this invitation?"
                                description="The link in their email stops working."
                            />
                        </SettingsListItem>
                    ))}
                </SettingsList>
            )}
        </>
    );
}

function Initials({ name }: { name: string }) {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');

    return (
        <span
            aria-hidden
            className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground"
        >
            {initials}
        </span>
    );
}

Members.layout = {
    breadcrumbs: [{ title: 'Members', href: membersIndex() }],
};
