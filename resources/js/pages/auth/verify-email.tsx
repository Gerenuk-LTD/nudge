import { Form, Head, usePage } from '@inertiajs/react';
import { CircleCheck, MailCheck } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Verify your email" />

            <div className="space-y-6">
                <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-4">
                    <MailCheck className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                    <p className="text-sm">
                        We sent a link to{' '}
                        <span className="font-medium">{auth.user?.email}</span>.
                        Open it to verify your address and carry on. It can take
                        a minute to arrive, so check your spam folder too.
                    </p>
                </div>

                {status === 'verification-link-sent' && (
                    <Alert>
                        <CircleCheck />
                        <AlertDescription className="text-foreground">
                            We sent you a new link.
                        </AlertDescription>
                    </Alert>
                )}

                <Form {...send.form()}>
                    {({ processing }) => (
                        <Button
                            variant="outline"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Send the link again
                        </Button>
                    )}
                </Form>

                <p className="text-center text-sm text-muted-foreground">
                    Wrong account? <TextLink href={logout()}>Log out</TextLink>
                </p>
            </div>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Check your inbox',
    description: 'Verify your email address to finish creating your account.',
};
