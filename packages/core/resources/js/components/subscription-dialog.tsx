import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ContactController from '@/actions/Gerenuk/Marketing/Http/Controllers/ContactController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { ContactStatus } from '@marketing/types';

export type SubscriptionTarget = {
    id: number;
    email: string;
    name: string | null;
    status: ContactStatus;
};

/**
 * Confirms unsubscribing a contact, or subscribing them again, and does it straight away. Open it by passing a contact;
 * `onDone` runs once the change is saved.
 */
export function SubscriptionDialog({
    contact,
    onClose,
    onDone,
}: {
    contact: SubscriptionTarget | null;
    onClose: () => void;
    onDone?: (contact: SubscriptionTarget) => void;
}) {
    const [processing, setProcessing] = useState(false);
    // Keeps the wording steady while the dialog animates closed after `contact` is cleared.
    const shown = useRef(contact);
    shown.current = contact ?? shown.current;
    const unsubscribing = shown.current?.status === 'subscribed';
    const who = shown.current?.name ?? shown.current?.email;

    const confirm = () => {
        if (!contact) {
            return;
        }

        const action = unsubscribing
            ? ContactController.unsubscribe
            : ContactController.resubscribe;

        router.post(
            action.url(contact.id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    onClose();
                    onDone?.(contact);
                },
            },
        );
    };

    return (
        <Dialog
            open={contact !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogTitle>
                    {unsubscribing
                        ? `Unsubscribe ${who}?`
                        : `Subscribe ${who} again?`}
                </DialogTitle>
                <DialogDescription>
                    {unsubscribing
                        ? 'They stop getting campaigns and automation emails straight away. They stay in your contacts, and their activity notes that you unsubscribed them.'
                        : 'Only do this if they asked to hear from you again. Emailing people who unsubscribed breaks anti-spam law and hurts your sending reputation.'}
                </DialogDescription>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant={unsubscribing ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={confirm}
                    >
                        {processing && <Spinner />}
                        {unsubscribing ? 'Unsubscribe' : 'Subscribe again'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
