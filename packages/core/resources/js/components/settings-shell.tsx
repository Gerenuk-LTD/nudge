import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { PageBody, PageHeader } from '@marketing/components/page-header';

export type SettingsTab = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
};

/**
 * A settings area: its heading, a tab for each page, and the current page's sections below.
 */
export function SettingsShell({
    title,
    description,
    tabs,
    children,
}: {
    title: string;
    description: string;
    tabs: SettingsTab[];
    children: ReactNode;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <PageBody>
            <div className="space-y-4">
                <PageHeader title={title} description={description} />

                <nav
                    aria-label={title}
                    className="-mx-4 flex gap-1 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
                >
                    {tabs.map((tab) => {
                        const active = isCurrentOrParentUrl(tab.href);

                        return (
                            <Link
                                key={toUrl(tab.href)}
                                href={tab.href}
                                prefetch
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                                    active
                                        ? 'border-foreground text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {tab.icon && <tab.icon className="size-4" />}
                                {tab.title}
                            </Link>
                        );
                    })}
                </nav>
            </div>

            <div className="flex max-w-3xl flex-col gap-6">{children}</div>
        </PageBody>
    );
}
