import { Form } from '@inertiajs/react';
import { Monitor, Smartphone } from 'lucide-react';
import { useState } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { SettingsSection } from '@nudge/components/settings-section';
import { StatusBadge } from '@nudge/components/status-badge';
import { formatDateTime } from '@nudge/lib/format';

export type Session = {
    id: string;
    browser: string | null;
    platform: string | null;
    ip_address: string | null;
    last_active_at: string;
    current: boolean;
};

const MOBILE = ['iPhone', 'iPad', 'Android'];

/**
 * The browsers the user is signed in on, with a way to sign out of all but this one.
 */
export default function ActiveSessions({ sessions }: { sessions: Session[] }) {
    const others = sessions.filter((session) => !session.current).length;

    return (
        <SettingsSection
            title="Where you're signed in"
            description="If you don't recognise a device, sign out of it and change your password."
            hint={
                others === 0
                    ? 'This is the only place you are signed in.'
                    : `Signed in on ${others} other ${others === 1 ? 'device' : 'devices'}.`
            }
            actions={others > 0 && <SignOutOthers />}
        >
            <ul className="divide-y rounded-lg border">
                {sessions.map((session) => {
                    const Icon =
                        session.platform && MOBILE.includes(session.platform)
                            ? Smartphone
                            : Monitor;

                    return (
                        <li
                            key={session.id}
                            className="flex items-center gap-3 px-4 py-3"
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/40 text-muted-foreground">
                                <Icon className="size-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                    {[session.browser, session.platform]
                                        .filter(Boolean)
                                        .join(' on ') || 'Unknown device'}
                                    {session.current && (
                                        <StatusBadge tone="success">
                                            This device
                                        </StatusBadge>
                                    )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {session.ip_address ?? 'Unknown address'}
                                    {!session.current &&
                                        ` · last active ${formatDateTime(session.last_active_at)}`}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </SettingsSection>
    );
}

function SignOutOthers() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Sign out other devices
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Sign out other devices?</DialogTitle>
                <DialogDescription>
                    Every other browser and device is signed out. You stay
                    signed in here. Enter your password to confirm.
                </DialogDescription>
                <Form
                    {...SecurityController.destroyOtherSessions.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="sessions-password">
                                    Password
                                </Label>
                                <PasswordInput
                                    id="sessions-password"
                                    name="password"
                                    autoFocus
                                    autoComplete="current-password"
                                />
                                <InputError message={errors.password} />
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="ghost">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button disabled={processing}>
                                    Sign out other devices
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
