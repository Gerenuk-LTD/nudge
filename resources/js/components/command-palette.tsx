import { router } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowRightLeft,
    Building2,
    CornerDownLeft,
    FilePlus2,
    Monitor,
    Moon,
    Search,
    Sun,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAppearance } from '@/hooks/use-appearance';
import { accountSettingsTabs } from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { index as automationsIndex } from '@/routes/marketing/automations';
import { index as campaignsIndex } from '@/routes/marketing/campaigns';
import { create as createContact } from '@/routes/marketing/contacts';
import { update as switchWorkspace } from '@/routes/marketing/current-workspace';
import { create as createImport } from '@/routes/marketing/imports';
import { create as createProvider } from '@/routes/marketing/providers';
import { create as createSegment } from '@/routes/marketing/segments';
import { useCan, useWorkspace } from '@marketing/hooks/use-workspace';
import { workspaceSettingsTabs } from '@marketing/layouts/workspace-settings-layout';
import { marketingNavItems } from '@marketing/navigation';
import type { Permission } from '@marketing/types';

type Href = NonNullable<InertiaLinkProps['href']>;

type Command = {
    id: string;
    group: string;
    label: string;
    icon: LucideIcon;
    run: () => void;
};

const creates: {
    label: string;
    href: Href;
    permission: Permission;
}[] = [
    {
        label: 'New campaign',
        href: campaignsIndex({ query: { new: 1 } }),
        permission: 'campaigns.manage',
    },
    {
        label: 'New automation',
        href: automationsIndex({ query: { new: 1 } }),
        permission: 'automations.manage',
    },
    {
        label: 'Add contact',
        href: createContact(),
        permission: 'contacts.manage',
    },
    {
        label: 'Import contacts',
        href: createImport(),
        permission: 'contacts.import',
    },
    {
        label: 'New segment',
        href: createSegment(),
        permission: 'segments.manage',
    },
    {
        label: 'Connect a provider',
        href: createProvider(),
        permission: 'providers.manage',
    },
];

/**
 * Search everything you can go to or do, from anywhere: ⌘K (Ctrl+K), or the search button this renders.
 * Arrow keys move through results, Enter runs one, Escape closes.
 */
export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const [isMac, setIsMac] = useState(true);
    const list = useRef<HTMLDivElement>(null);
    const can = useCan();
    const workspace = useWorkspace();
    const { updateAppearance } = useAppearance();

    useEffect(() => {
        const toggle = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                setOpen((isOpen) => !isOpen);
            }
        };

        window.addEventListener('keydown', toggle);
        setIsMac(/Mac|iPhone|iPad/.test(navigator.userAgent));

        return () => window.removeEventListener('keydown', toggle);
    }, []);

    useEffect(() => {
        list.current
            ?.querySelector(`[data-index="${active}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const visit = (href: Href) => () => router.visit(href);

    const commands: Command[] = [
        ...marketingNavItems
            .filter((item) => can(item.permission))
            .map((item) => ({
                id: `go-${item.title}`,
                group: 'Go to',
                label: item.title,
                icon: item.icon,
                run: visit(item.href),
            })),
        ...creates
            .filter((item) => can(item.permission))
            .map((item) => ({
                id: `create-${item.label}`,
                group: 'Create',
                label: item.label,
                icon: FilePlus2,
                run: visit(item.href),
            })),
        ...(workspace
            ? workspaceSettingsTabs
                  .filter((tab) => !tab.permission || can(tab.permission))
                  .map((tab) => ({
                      id: `workspace-${tab.title}`,
                      group: 'Workspace settings',
                      label: tab.title,
                      icon: tab.icon ?? Building2,
                      run: visit(tab.href),
                  }))
            : []),
        ...accountSettingsTabs.map((tab) => ({
            id: `account-${tab.title}`,
            group: 'Account',
            label: tab.title,
            icon: tab.icon ?? Building2,
            run: visit(tab.href),
        })),
        ...(workspace?.all ?? [])
            .filter((item) => item.id !== workspace?.current.id)
            .map((item) => ({
                id: `switch-${item.id}`,
                group: 'Switch workspace',
                label: item.name,
                icon: ArrowRightLeft,
                run: () =>
                    router.visit(switchWorkspace(), {
                        data: { workspace_id: item.id },
                    }),
            })),
        ...(
            [
                ['light', 'Light theme', Sun],
                ['dark', 'Dark theme', Moon],
                ['system', 'System theme', Monitor],
            ] as const
        ).map(([value, label, icon]) => ({
            id: `theme-${value}`,
            group: 'Theme',
            label,
            icon,
            run: () => updateAppearance(value),
        })),
    ];

    const terms = query.toLowerCase().split(/\s+/u).filter(Boolean);
    const results = commands.filter((command) => {
        const text = `${command.label} ${command.group}`.toLowerCase();

        return terms.every((term) => text.includes(term));
    });
    const groups = Map.groupBy(results, (command) => command.group);

    const close = (isOpen: boolean) => {
        setOpen(isOpen);
        setQuery('');
        setActive(0);
    };

    const run = (command: Command | undefined) => {
        if (!command) {
            return;
        }

        close(false);
        command.run();
    };

    const navigate = (event: ReactKeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActive(
                (current) =>
                    (current + step + results.length) %
                    Math.max(results.length, 1),
            );
        } else if (event.key === 'Enter') {
            event.preventDefault();
            run(results[active]);
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label="Search pages and actions"
                className="flex h-8 items-center gap-2 rounded-md border bg-background px-2.5 text-sm text-muted-foreground shadow-xs transition-colors hover:text-foreground sm:w-60"
            >
                <Search className="size-4 shrink-0" />
                <span className="hidden sm:inline">Search…</span>
                <span className="ml-auto hidden items-center gap-0.5 sm:flex">
                    <Kbd>{isMac ? '⌘' : 'Ctrl'}</Kbd>
                    <Kbd>K</Kbd>
                </span>
            </button>
            <Dialog open={open} onOpenChange={close}>
                <DialogContent className="top-[18%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl [&>button:last-child]:hidden">
                    <DialogTitle className="sr-only">
                        Command palette
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Search for a page or an action and press Enter to run
                        it.
                    </DialogDescription>

                    <div className="flex items-center gap-2.5 border-b px-4">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            autoFocus
                            role="combobox"
                            aria-expanded="true"
                            aria-controls="command-results"
                            aria-activedescendant={
                                results[active]
                                    ? `command-${results[active].id}`
                                    : undefined
                            }
                            placeholder="Search pages and actions…"
                            className="h-12 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActive(0);
                            }}
                            onKeyDown={navigate}
                        />
                    </div>

                    <div
                        ref={list}
                        id="command-results"
                        role="listbox"
                        aria-label="Results"
                        className="max-h-80 overflow-y-auto p-1.5"
                    >
                        {results.length === 0 && (
                            <p className="px-3 py-8 text-center text-sm text-muted-foreground">
                                Nothing matches “{query}”.
                            </p>
                        )}
                        {[...groups].map(([group, items]) => (
                            <div key={group} role="group" aria-label={group}>
                                <p className="px-2.5 pt-2 pb-1 text-xs font-medium text-muted-foreground">
                                    {group}
                                </p>
                                {items.map((command) => {
                                    const index = results.indexOf(command);

                                    return (
                                        <div
                                            key={command.id}
                                            id={`command-${command.id}`}
                                            role="option"
                                            aria-selected={index === active}
                                            data-index={index}
                                            onMouseMove={() => setActive(index)}
                                            onClick={() => run(command)}
                                            className={cn(
                                                'flex h-9 cursor-pointer items-center gap-2.5 rounded-md px-2.5 text-sm',
                                                index === active
                                                    ? 'bg-accent text-accent-foreground'
                                                    : 'text-foreground/90',
                                            )}
                                        >
                                            <command.icon className="size-4 shrink-0 text-muted-foreground" />
                                            <span className="truncate">
                                                {command.label}
                                            </span>
                                            {index === active && (
                                                <CornerDownLeft className="ml-auto size-3.5 text-muted-foreground" />
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>

                    <div className="flex items-center gap-4 border-t bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1.5">
                            <Kbd>↑</Kbd>
                            <Kbd>↓</Kbd>
                            to move
                        </span>
                        <span className="flex items-center gap-1.5">
                            <Kbd>↵</Kbd>
                            to open
                        </span>
                        <span className="flex items-center gap-1.5">
                            <Kbd>esc</Kbd>
                            to close
                        </span>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

export function Kbd({ children }: { children: string }) {
    return (
        <kbd className="inline-flex h-5 min-w-5 items-center justify-center rounded border bg-background px-1 font-sans text-xs font-medium text-muted-foreground shadow-xs">
            {children}
        </kbd>
    );
}
