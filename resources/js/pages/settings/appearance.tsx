import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import { SettingsSection } from '@marketing/components/settings-section';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance settings" />

            <SettingsSection
                title="Theme"
                description="Choose how the app looks on this device. System follows your operating system's setting."
            >
                <AppearanceTabs />
            </SettingsSection>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [{ title: 'Account settings', href: editAppearance() }],
};
