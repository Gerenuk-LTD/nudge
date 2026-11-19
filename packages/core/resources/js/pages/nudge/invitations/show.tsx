import { Form, Head } from '@inertiajs/react';
import InvitationController from '@/actions/Gerenuk/Nudge/Http/Controllers/InvitationController';
import InputError from '@/components/input-error';
import { CircleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { WorkspaceAvatar } from '@nudge/components/workspace-avatar';

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
                <Alert>
                    <CircleAlert />
                    <AlertTitle>This link doesn't work</AlertTitle>
                    <AlertDescription>
                        It may have been revoked or already used. Ask whoever
                        invited you for a new one.
                    </AlertDescription>
                </Alert>
            </>
        );
    }

    return (
        <>
            <Head title="Accept invitation" />

            <div className="space-y-6">
                <div className="flex items-center gap-3 rounded-lg border bg-muted/40 p-3">
                    <WorkspaceAvatar
                        workspace={{ name: invitation.workspace ?? '?' }}
                        className="size-9 text-xs"
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">
                            {invitation.workspace}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            Invitation for {invitation.email}
                        </p>
                    </div>
                    <Badge variant="outline">{invitation.role}</Badge>
                </div>

                {invitation.expired ? (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertTitle>This invitation has expired</AlertTitle>
                        <AlertDescription>
                            Ask whoever invited you to send a new one.
                        </AlertDescription>
                    </Alert>
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
                                    Join {invitation.workspace}
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
    title: "You're invited",
    description: 'Join the workspace to start working with your team.',
};
