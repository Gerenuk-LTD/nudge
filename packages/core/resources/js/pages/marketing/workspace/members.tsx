import { Form, Head, router, usePage } from '@inertiajs/react';
import MemberController from '@/actions/Gerenuk/Marketing/Http/Controllers/MemberController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as membersIndex } from '@/routes/marketing/members';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { Cell, DataTable } from '@marketing/components/data-table';
import { NativeSelect } from '@marketing/components/native-select';
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

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Invite someone"
                    description="They get an email with a link to join"
                />

                <Form
                    {...MemberController.invite.form()}
                    resetOnSuccess={['email']}
                    options={{ preserveScroll: true }}
                    className="flex flex-wrap items-start gap-2"
                >
                    {({ processing, errors: formErrors }) => (
                        <>
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
                            <Button disabled={processing}>Send invite</Button>
                        </>
                    )}
                </Form>
            </div>

            <div className="space-y-4">
                <Heading variant="small" title="Members" />
                <InputError message={errors.member} />

                <DataTable headers={['Name', 'Role', '']}>
                    {members.map((member) => (
                        <tr key={member.id}>
                            <Cell>
                                <p className="font-medium">{member.name}</p>
                                <p className="text-xs text-muted-foreground">
                                    {member.email}
                                </p>
                            </Cell>
                            <Cell>
                                <NativeSelect
                                    aria-label={`Role for ${member.name}`}
                                    value={member.role_id}
                                    onChange={(event) =>
                                        router.put(
                                            MemberController.update.url(
                                                member.id,
                                            ),
                                            {
                                                role_id: Number(
                                                    event.target.value,
                                                ),
                                            },
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
                            </Cell>
                            <Cell className="text-right">
                                <ConfirmDelete
                                    url={MemberController.destroy.url(
                                        member.id,
                                    )}
                                    trigger="Remove"
                                    title={`Remove ${member.name}?`}
                                    description="They lose access to this workspace straight away."
                                />
                            </Cell>
                        </tr>
                    ))}
                </DataTable>
            </div>

            {invitations.length > 0 && (
                <div className="space-y-4">
                    <Heading variant="small" title="Pending invitations" />
                    <DataTable headers={['Email', 'Role', '']}>
                        {invitations.map((invitation) => (
                            <tr key={invitation.id}>
                                <Cell>
                                    {invitation.email}{' '}
                                    {invitation.expired && (
                                        <Badge variant="outline">Expired</Badge>
                                    )}
                                </Cell>
                                <Cell>{invitation.role}</Cell>
                                <Cell className="text-right">
                                    <ConfirmDelete
                                        url={MemberController.revokeInvitation.url(
                                            invitation.id,
                                        )}
                                        trigger="Revoke"
                                        title="Revoke this invitation?"
                                        description="The link in their email stops working."
                                    />
                                </Cell>
                            </tr>
                        ))}
                    </DataTable>
                </div>
            )}
        </>
    );
}

Members.layout = {
    breadcrumbs: [{ title: 'Members', href: membersIndex() }],
};
