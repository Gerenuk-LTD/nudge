import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { formatDate } from '@marketing/lib/format';

/**
 * Shown to recipients, so it uses no app layout and asks before unsubscribing: link scanners
 * open unsubscribe links too, and a page that unsubscribed on load would remove real readers.
 * People who only want fewer emails for a while can pause for 30 or 90 days instead; pausing
 * also needs a click, and unsubscribing stays available while paused.
 */
export default function Unsubscribe({
    workspace,
    email,
    unsubscribed,
    pausedUntil,
    action,
    pauseAction,
}: {
    workspace: string;
    email: string;
    unsubscribed: boolean;
    pausedUntil: string | null;
    action: string;
    pauseAction: string;
}) {
    const form = useForm({});
    const pauseForm = useForm({ days: 30 });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(new URL(action).pathname);
    };

    const pause = (days: 30 | 90) => {
        pauseForm.transform(() => ({ days }));
        pauseForm.post(new URL(pauseAction).pathname, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Unsubscribe" />

            <main className="flex min-h-svh items-center justify-center bg-muted/40 p-6">
                <div className="w-full max-w-md space-y-4 rounded-xl border bg-card p-6 text-center shadow-xs">
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
                            {pausedUntil ? (
                                <>
                                    <h1 className="text-lg font-semibold">
                                        Emails are paused until{' '}
                                        {formatDate(pausedUntil)}.
                                    </h1>
                                    <p className="text-sm text-muted-foreground">
                                        {email} won't get campaigns from{' '}
                                        {workspace} until then. You can still
                                        unsubscribe completely.
                                    </p>
                                </>
                            ) : (
                                <>
                                    <h1 className="text-lg font-semibold">
                                        Unsubscribe from {workspace}?
                                    </h1>
                                    <p className="text-sm text-muted-foreground">
                                        {email} will stop receiving their
                                        campaigns. Just need a break? Pause
                                        instead, and emails start again by
                                        themselves.
                                    </p>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            disabled={pauseForm.processing}
                                            onClick={() => pause(30)}
                                        >
                                            Pause for 30 days
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            disabled={pauseForm.processing}
                                            onClick={() => pause(90)}
                                        >
                                            Pause for 90 days
                                        </Button>
                                    </div>
                                </>
                            )}
                            <Button
                                className="w-full"
                                variant={pausedUntil ? 'outline' : 'default'}
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
