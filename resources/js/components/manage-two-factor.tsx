import { router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { SettingsSection } from '@nudge/components/settings-section';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePasswordConfirmation } from '@/hooks/use-password-confirmation';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const [processing, setProcessing] = useState(false);
    const { requirePassword, dialog } = usePasswordConfirmation();

    const visit = (method: 'post' | 'delete', url: string, then?: () => void) =>
        requirePassword(() =>
            router.visit(url, {
                method,
                preserveScroll: true,
                // Keeps the setup modal's open state across the redirect back to this page.
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: then,
            }),
        );
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <SettingsSection
            title="Two-factor authentication"
            badge={
                twoFactorEnabled ? (
                    <Badge variant="secondary">
                        <ShieldCheck />
                        On
                    </Badge>
                ) : (
                    <Badge variant="outline">Off</Badge>
                )
            }
            description={
                twoFactorEnabled
                    ? 'You enter a code from your authenticator app each time you sign in.'
                    : 'Ask for a code from an authenticator app, such as 1Password or Google Authenticator, each time you sign in.'
            }
            actions={
                twoFactorEnabled ? (
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={processing}
                        onClick={() => visit('delete', disable.url())}
                    >
                        Turn off
                    </Button>
                ) : hasSetupData ? (
                    <Button size="sm" onClick={() => setShowSetupModal(true)}>
                        Continue setup
                    </Button>
                ) : (
                    <Button
                        size="sm"
                        disabled={processing}
                        onClick={() =>
                            visit('post', enable.url(), () =>
                                setShowSetupModal(true),
                            )
                        }
                    >
                        Turn on
                    </Button>
                )
            }
        >
            {twoFactorEnabled && (
                <TwoFactorRecoveryCodes
                    recoveryCodesList={recoveryCodesList}
                    fetchRecoveryCodes={fetchRecoveryCodes}
                    requirePassword={requirePassword}
                    errors={errors}
                />
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />

            {dialog}
        </SettingsSection>
    );
}
