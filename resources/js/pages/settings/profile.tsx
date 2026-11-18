import { Form, Head, Link, usePage } from '@inertiajs/react';
import { MailWarning } from 'lucide-react';
import { useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/input-error';
import { SettingsSection } from '@nudge/components/settings-section';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const getInitials = useInitials();
    const unverified = mustVerifyEmail && auth.user.email_verified_at === null;
    const [email, setEmail] = useState(auth.user.email);
    const emailChanged =
        email.trim().toLowerCase() !== auth.user.email.toLowerCase();

    return (
        <>
            <Head title="Profile settings" />

            <Form
                {...ProfileController.update.form()}
                options={{ preserveScroll: true }}
            >
                {({ processing, recentlySuccessful, errors }) => (
                    <SettingsSection
                        title="Profile"
                        description="How you appear to the people you work with."
                        hint={
                            recentlySuccessful
                                ? 'Saved.'
                                : mustVerifyEmail &&
                                  'A new email address needs verifying before you can use it.'
                        }
                        actions={
                            <Button
                                size="sm"
                                disabled={processing}
                                data-test="update-profile-button"
                            >
                                Save
                            </Button>
                        }
                    >
                        <div className="flex items-center gap-4">
                            <Avatar className="size-12 rounded-full">
                                <AvatarImage
                                    src={auth.user.avatar}
                                    alt={auth.user.name}
                                />
                                <AvatarFallback className="rounded-full bg-muted text-sm font-medium">
                                    {getInitials(auth.user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0">
                                <p className="truncate font-medium">
                                    {auth.user.name}
                                </p>
                                <p className="truncate text-sm text-muted-foreground">
                                    {auth.user.email}
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={auth.user.name}
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid content-start gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    defaultValue={auth.user.email}
                                    onChange={(event) =>
                                        setEmail(event.target.value)
                                    }
                                    required
                                    autoComplete="username"
                                    placeholder="you@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            {emailChanged && (
                                <div className="grid content-start gap-2 sm:col-span-2">
                                    <Label htmlFor="current_password">
                                        Current password
                                    </Label>
                                    <Input
                                        id="current_password"
                                        type="password"
                                        name="current_password"
                                        required
                                        autoComplete="current-password"
                                        placeholder="Confirm your password to change your email"
                                    />
                                    <InputError
                                        message={errors.current_password}
                                    />
                                </div>
                            )}
                        </div>

                        {unverified && (
                            <Alert>
                                <MailWarning />
                                <AlertTitle>
                                    Your email address is unverified
                                </AlertTitle>
                                <AlertDescription>
                                    {status === 'verification-link-sent' ? (
                                        <p>
                                            We sent a new verification link to{' '}
                                            {auth.user.email}.
                                        </p>
                                    ) : (
                                        <p>
                                            Check your inbox for the link, or{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="font-medium text-foreground underline underline-offset-4"
                                            >
                                                send it again
                                            </Link>
                                            .
                                        </p>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}
                    </SettingsSection>
                )}
            </Form>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [{ title: 'Account settings', href: edit() }],
};
