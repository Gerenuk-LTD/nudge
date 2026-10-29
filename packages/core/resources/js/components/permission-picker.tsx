import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import type { Permission } from '@marketing/types';

type PermissionOption = { value: Permission; label: string };

/** Permissions by the area they cover, each with a short name for summaries. */
const GROUPS: { title: string; permissions: [Permission, string][] }[] = [
    {
        title: 'Contacts',
        permissions: [
            ['contacts.view', 'view'],
            ['contacts.manage', 'edit'],
            ['contacts.import', 'import'],
            ['segments.manage', 'segments'],
        ],
    },
    {
        title: 'Campaigns',
        permissions: [
            ['campaigns.view', 'view'],
            ['campaigns.manage', 'edit'],
            ['campaigns.send', 'send'],
            ['campaigns.approve', 'approve'],
        ],
    },
    {
        title: 'Automations',
        permissions: [
            ['automations.view', 'view'],
            ['automations.manage', 'edit'],
        ],
    },
    {
        title: 'Sending',
        permissions: [['providers.manage', 'providers and settings']],
    },
    {
        title: 'Workspace',
        permissions: [
            ['members.manage', 'members'],
            ['roles.manage', 'roles'],
            ['api_tokens.manage', 'API tokens'],
            ['workspace.manage', 'rename'],
        ],
    },
];

/** Doing something needs seeing it, so these switch on the permission they rely on, and off with it. */
const NEEDS: Partial<Record<Permission, Permission>> = {
    'contacts.manage': 'contacts.view',
    'contacts.import': 'contacts.view',
    'segments.manage': 'contacts.view',
    'campaigns.manage': 'campaigns.view',
    'campaigns.send': 'campaigns.view',
    'campaigns.approve': 'campaigns.view',
    'automations.manage': 'automations.view',
};

/**
 * What a set of permissions allows, one line per area, such as "Contacts: view, edit, import".
 */
export function permissionSummary(permissions: Permission[]): string[] {
    return GROUPS.flatMap((group) => {
        const granted = group.permissions
            .filter(([permission]) => permissions.includes(permission))
            .map(([, short]) => short);

        return granted.length === 0
            ? []
            : [`${group.title}: ${granted.join(', ')}`];
    });
}

/** The areas permissions are grouped into, in order. */
export const PERMISSION_AREAS = GROUPS.map((group) => group.title);

/**
 * How much of each area a set of permissions covers: everything, nothing, or the short names of what it grants.
 */
export function permissionLevels(
    permissions: Permission[],
): { area: string; level: 'full' | 'none' | 'some'; label: string }[] {
    return GROUPS.map((group) => {
        const granted = group.permissions.filter(([permission]) =>
            permissions.includes(permission),
        );

        if (granted.length === 0) {
            return { area: group.title, level: 'none', label: 'No access' };
        }

        if (granted.length === group.permissions.length) {
            return { area: group.title, level: 'full', label: 'Full' };
        }

        const label = granted.map(([, short]) => short).join(', ');

        return {
            area: group.title,
            level: 'some',
            label: label.charAt(0).toUpperCase() + label.slice(1),
        };
    });
}

/**
 * The permissions as checkboxes grouped by area, with a switch for each whole area. Granting something also
 * grants seeing it, and taking away seeing something takes away what relies on it.
 */
export function PermissionPicker({
    options,
    value,
    onChange,
}: {
    options: PermissionOption[];
    value: Permission[];
    onChange: (permissions: Permission[]) => void;
}) {
    const label = (permission: Permission) =>
        options.find((option) => option.value === permission)?.label ??
        permission;

    const set = (permission: Permission, on: boolean) => {
        const next = new Set(value);

        if (on) {
            next.add(permission);

            if (NEEDS[permission]) {
                next.add(NEEDS[permission]);
            }
        } else {
            next.delete(permission);

            for (const [dependent, needed] of Object.entries(NEEDS)) {
                if (needed === permission) {
                    next.delete(dependent as Permission);
                }
            }
        }

        onChange(
            options
                .map((option) => option.value)
                .filter((option) => next.has(option)),
        );
    };

    const setGroup = (permissions: Permission[], on: boolean) => {
        const next = new Set(value);
        permissions.forEach((permission) =>
            on ? next.add(permission) : next.delete(permission),
        );
        onChange(
            options
                .map((option) => option.value)
                .filter((option) => next.has(option)),
        );
    };

    return (
        <div className="divide-y rounded-lg border">
            {GROUPS.map((group) => {
                const permissions = group.permissions
                    .map(([permission]) => permission)
                    .filter((permission) =>
                        options.some((option) => option.value === permission),
                    );
                const granted = permissions.filter((permission) =>
                    value.includes(permission),
                ).length;

                if (permissions.length === 0) {
                    return null;
                }

                return (
                    <fieldset key={group.title} className="px-4 py-3">
                        <div className="mb-2 flex items-center justify-between gap-3">
                            <legend className="text-13 font-semibold">
                                {group.title}
                            </legend>
                            {permissions.length > 1 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setGroup(
                                            permissions,
                                            granted < permissions.length,
                                        )
                                    }
                                    className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                                >
                                    {granted < permissions.length
                                        ? 'Allow all'
                                        : 'Allow none'}
                                </button>
                            )}
                        </div>
                        <div className="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                            {permissions.map((permission) => (
                                <label
                                    key={permission}
                                    className={cn(
                                        'flex items-start gap-2 text-sm',
                                        !value.includes(permission) &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={value.includes(permission)}
                                        onCheckedChange={(checked) =>
                                            set(permission, checked === true)
                                        }
                                    />
                                    {label(permission)}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                );
            })}
        </div>
    );
}
