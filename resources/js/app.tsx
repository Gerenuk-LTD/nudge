import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import WorkspaceSettingsLayout from '@nudge/layouts/workspace-settings-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Nudge';

// Pages come from this app and from the core package, whose pages are all under `nudge/`.
const pages = {
    ...import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx'),
    ...import.meta.glob<{ default: ComponentType }>('@nudge/pages/**/*.tsx'),
};

function resolvePage(name: string) {
    const path = Object.keys(pages).find((key) =>
        key.endsWith(`/pages/${name}.tsx`),
    );

    if (!path) {
        throw new Error(`Page not found: ${name}`);
    }

    return pages[path]().then((module) => module.default);
}

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: resolvePage,
    layout: (name) => {
        switch (true) {
            case name.startsWith('nudge/public/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            case name.startsWith('nudge/workspace/'):
                return [AppLayout, WorkspaceSettingsLayout];
            case name === 'nudge/workspaces/create' ||
                name === 'nudge/invitations/show':
                return AuthLayout;
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
