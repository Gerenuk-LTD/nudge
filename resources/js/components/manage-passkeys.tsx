import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import PasskeyItem from '@/components/passkey-item';
import PasskeyRegistration from '@/components/passkey-register';
import { SettingsSection } from '@marketing/components/settings-section';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

const EmptyState = () => {
    return (
        <div className="px-6 py-8 text-center">
            <div className="mx-auto mb-3 flex size-10 items-center justify-center rounded-lg border bg-background">
                <KeyRound className="size-5 text-muted-foreground" />
            </div>
            <p className="text-sm font-medium">No passkeys yet</p>
            <p className="mt-1 text-sm text-muted-foreground">
                Use your fingerprint, face or device PIN instead of a password.
            </p>
        </div>
    );
};

export default function ManagePasskeys(props: Props) {
    const passkeys = props.passkeys ?? [];

    const handleDelete = (id: number, onError: () => void) => {
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onError,
        });
    };

    const handleRegisterSuccess = () => {
        router.reload();
    };

    if (!(props.canManagePasskeys ?? false)) {
        return null;
    }

    return (
        <SettingsSection
            title="Passkeys"
            description="Sign in with your fingerprint, face or device PIN. Passkeys can't be phished or reused on other sites."
        >
            <div className="overflow-hidden rounded-md border">
                {passkeys.length > 0 ? (
                    passkeys.map((passkey) => (
                        <PasskeyItem
                            key={passkey.id}
                            passkey={passkey}
                            onDelete={handleDelete}
                        />
                    ))
                ) : (
                    <EmptyState />
                )}
            </div>

            <PasskeyRegistration onSuccess={handleRegisterSuccess} />
        </SettingsSection>
    );
}
