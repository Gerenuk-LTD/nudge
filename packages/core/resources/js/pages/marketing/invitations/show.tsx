import { Form, Head } from '@inertiajs/react';
import InvitationController from '@/actions/Gerenuk/Marketing/Http/Controllers/InvitationController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Invitation = {
    email: string;
    workspace: string | null;
    role: string;
    expired: boolean;
};

export default function ShowInvitation({
    token,
    invitation,
}: {
    token: string;
    invitation: Invitation | null;
}) {
    if (!invitation) {
        return (
            <>
                <Head title="Invitation not found" />
                <p className="text-center text-sm text-muted-foreground">
                    This invitation link is not valid. It may have been revoked
                    or already used.
                </p>
            </>
        );
    }

    return (
        <>
            <Head title="Accept invitation" />

            <div className="space-y-6 text-center">
                <p className="text-sm">
                    You have been invited to join{' '}
                    <strong>{invitation.workspace}</strong> as{' '}
                    <strong>{invitation.role}</strong>. The invitation was sent
                    to {invitation.email}.
                </p>

                {invitation.expired ? (
                    <p className="text-sm text-red-600 dark:text-red-400">
                        This invitation has expired. Ask for a new one.
                    </p>
                ) : (
                    <Form {...InvitationController.accept.form(token)}>
                        {({ processing, errors }) => (
                            <div className="space-y-3">
                                <InputError message={errors.invitation} />
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Accept invitation
                                </Button>
                            </div>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

ShowInvitation.layout = {
    title: 'Workspace invitation',
};
