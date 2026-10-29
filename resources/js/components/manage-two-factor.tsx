import { Form } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { SettingsSection } from '@marketing/components/settings-section';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
                    <Form
                        {...disable.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant="outline"
                                size="sm"
                                type="submit"
                                disabled={processing}
                            >
                                Turn off
                            </Button>
                        )}
                    </Form>
                ) : hasSetupData ? (
                    <Button size="sm" onClick={() => setShowSetupModal(true)}>
                        Continue setup
                    </Button>
                ) : (
                    <Form
                        {...enable.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setShowSetupModal(true)}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                type="submit"
                                disabled={processing}
                            >
                                Turn on
                            </Button>
                        )}
                    </Form>
                )
            }
        >
            {twoFactorEnabled && (
                <TwoFactorRecoveryCodes
                    recoveryCodesList={recoveryCodesList}
                    fetchRecoveryCodes={fetchRecoveryCodes}
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
        </SettingsSection>
    );
}
