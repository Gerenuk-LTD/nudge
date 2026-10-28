import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';

/**
 * Shown to recipients, so it uses no app layout and asks before unsubscribing: link scanners
 * open unsubscribe links too, and a page that unsubscribed on load would remove real readers.
 */
export default function Unsubscribe({
    workspace,
    email,
    unsubscribed,
    action,
}: {
    workspace: string;
    email: string;
    unsubscribed: boolean;
    action: string;
}) {
    const form = useForm({});

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(new URL(action).pathname);
    };

    return (
        <>
            <Head title="Unsubscribe" />

            <main className="flex min-h-svh items-center justify-center bg-muted/40 p-6">
                <div className="w-full max-w-md space-y-4 rounded-xl border bg-background p-6 text-center shadow-sm">
                    {unsubscribed ? (
                        <>
                            <h1 className="text-lg font-semibold">
                                You are unsubscribed
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {email} will not get any more campaigns from{' '}
                                {workspace}.
                            </p>
                        </>
                    ) : (
                        <form onSubmit={submit} className="space-y-4">
                            <h1 className="text-lg font-semibold">
                                Unsubscribe from {workspace}?
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {email} will stop receiving their campaigns.
                            </p>
                            <Button
                                className="w-full"
                                disabled={form.processing}
                            >
                                Unsubscribe
                            </Button>
                        </form>
                    )}
                </div>
            </main>
        </>
    );
}
