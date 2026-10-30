import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { confirmation } from '@/routes/password';
import { store as confirmPassword } from '@/routes/password/confirm';

/**
 * Runs a sensitive action, such as turning off two-factor authentication, once the password has been confirmed
 * recently. If it has not, asks for it in a dialog on the page rather than sending the user to a separate page.
 * Render `dialog` once in the component that uses it.
 */
export function usePasswordConfirmation() {
    const status = useHttp();
    const form = useHttp({ password: '' });
    const [pending, setPending] = useState<(() => void) | null>(null);

    const isConfirmed = async (): Promise<boolean> => {
        try {
            const { confirmed } = (await status.submit(confirmation())) as {
                confirmed: boolean;
            };

            return confirmed;
        } catch {
            return false;
        }
    };

    const requirePassword = async (action: () => void) => {
        if (await isConfirmed()) {
            action();

            return;
        }

        form.reset();
        form.clearErrors();
        setPending(() => action);
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        try {
            await form.submit(confirmPassword());
        } catch {
            // Fortify answers a correct password with an empty body, which is not JSON, so success is checked below.
        }

        // Only a confirmed password runs the action; a wrong one leaves the dialog open with its error.
        if (await isConfirmed()) {
            const action = pending;
            setPending(null);
            action?.();
        }
    };

    const dialog = (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => !open && setPending(null)}
        >
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="space-y-1.5">
                        <DialogTitle>Confirm your password</DialogTitle>
                        <DialogDescription>
                            This changes how you sign in, so we check it's you
                            first. You won't be asked again for a while.
                        </DialogDescription>
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="confirm-password">Password</Label>
                        <PasswordInput
                            id="confirm-password"
                            autoFocus
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(event) =>
                                form.setData('password', event.target.value)
                            }
                        />
                        <InputError message={form.errors.password} />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            disabled={
                                form.processing || form.data.password === ''
                            }
                        >
                            {form.processing && <Spinner />}
                            Confirm
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );

    return { requirePassword, dialog };
}
