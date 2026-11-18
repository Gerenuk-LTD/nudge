import { Palette, ShieldCheck, UserRound } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { SettingsShell } from '@nudge/components/settings-shell';
import type { SettingsTab } from '@nudge/components/settings-shell';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

/** The account settings pages, also offered by the command palette. */
export const accountSettingsTabs: SettingsTab[] = [
    { title: 'Profile', href: editProfile(), icon: UserRound },
    { title: 'Security', href: editSecurity(), icon: ShieldCheck },
    { title: 'Appearance', href: editAppearance(), icon: Palette },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    return (
        <SettingsShell
            title="Account settings"
            description="Your profile, how you sign in and how the app looks. These apply in every workspace."
            tabs={accountSettingsTabs}
        >
            {children}
        </SettingsShell>
    );
}
