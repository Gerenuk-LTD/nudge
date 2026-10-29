import { Code, Mail, Settings, ShieldCheck, Users } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { SettingsShell } from '@marketing/components/settings-shell';
import type { SettingsTab } from '@marketing/components/settings-shell';
import { index as apiTokensIndex } from '@/routes/marketing/api-tokens';
import { index as membersIndex } from '@/routes/marketing/members';
import { index as rolesIndex } from '@/routes/marketing/roles';
import { edit as sendingEdit } from '@/routes/marketing/sending';
import { edit as workspaceEdit } from '@/routes/marketing/workspace';
import { useCan, useWorkspace } from '@marketing/hooks/use-workspace';
import type { Permission } from '@marketing/types';

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
