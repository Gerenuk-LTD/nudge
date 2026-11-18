import {
    Code,
    Mail,
    ScrollText,
    Settings,
    ShieldCheck,
    Users,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { SettingsShell } from '@nudge/components/settings-shell';
import type { SettingsTab } from '@nudge/components/settings-shell';
import { index as apiLogIndex } from '@/routes/nudge/api-log';
import { index as apiTokensIndex } from '@/routes/nudge/api-tokens';
import { index as membersIndex } from '@/routes/nudge/members';
import { index as rolesIndex } from '@/routes/nudge/roles';
import { edit as sendingEdit } from '@/routes/nudge/sending';
import { edit as workspaceEdit } from '@/routes/nudge/workspace';
import { useCan, useWorkspace } from '@nudge/hooks/use-workspace';
import type { Permission } from '@nudge/types';

/** The workspace settings pages and the permission each needs, also offered by the command palette. */
export const workspaceSettingsTabs: (SettingsTab & {
    permission?: Permission;
})[] = [
    { title: 'General', href: workspaceEdit(), icon: Settings },
    {
        title: 'Sending',
        href: sendingEdit(),
        icon: Mail,
        permission: 'providers.manage',
    },
    {
        title: 'Members',
        href: membersIndex(),
        icon: Users,
        permission: 'members.manage',
    },
    {
        title: 'Roles',
        href: rolesIndex(),
        icon: ShieldCheck,
        permission: 'roles.manage',
    },
    {
        title: 'API tokens',
        href: apiTokensIndex(),
        icon: Code,
        permission: 'api_tokens.manage',
    },
    {
        title: 'API log',
        href: apiLogIndex(),
        icon: ScrollText,
        permission: 'api_tokens.manage',
    },
];

export default function WorkspaceSettingsLayout({
    children,
}: PropsWithChildren) {
    const can = useCan();
    const workspace = useWorkspace();

    return (
        <SettingsShell
            title="Workspace settings"
            description={`Manage ${workspace?.current.name ?? 'this workspace'}, its members and API access.`}
            tabs={workspaceSettingsTabs.filter(
                (tab) => !tab.permission || can(tab.permission),
            )}
        >
            {children}
        </SettingsShell>
    );
}
