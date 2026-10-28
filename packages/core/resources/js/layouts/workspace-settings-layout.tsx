import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { index as apiTokensIndex } from '@/routes/marketing/api-tokens';
import { index as membersIndex } from '@/routes/marketing/members';
import { index as rolesIndex } from '@/routes/marketing/roles';
import { edit as sendingEdit } from '@/routes/marketing/sending';
import { edit as workspaceEdit } from '@/routes/marketing/workspace';
import { useCan } from '@marketing/hooks/use-workspace';
import type { Permission } from '@marketing/types';

const items: {
    title: string;
    href: ReturnType<typeof workspaceEdit>;
    permission?: Permission;
}[] = [
    { title: 'General', href: workspaceEdit() },
    {
        title: 'Sending',
        href: sendingEdit(),
        permission: 'providers.manage',
    },
    { title: 'Members', href: membersIndex(), permission: 'members.manage' },
    { title: 'Roles', href: rolesIndex(), permission: 'roles.manage' },
    {
        title: 'API tokens',
        href: apiTokensIndex(),
        permission: 'api_tokens.manage',
    },
];

export default function WorkspaceSettingsLayout({
    children,
}: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const can = useCan();

    return (
        <div className="px-4 py-6">
            <Heading
                title="Workspace settings"
                description="Manage this workspace, its members and API access"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Workspace settings"
                    >
                        {items
                            .filter(
                                (item) =>
                                    !item.permission || can(item.permission),
                            )
                            .map((item) => (
                                <Button
                                    key={toUrl(item.href)}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn('w-full justify-start', {
                                        'bg-muted': isCurrentOrParentUrl(
                                            item.href,
                                        ),
                                    })}
                                >
                                    <Link href={item.href}>{item.title}</Link>
                                </Button>
                            ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-3xl">
                    <section className="space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
