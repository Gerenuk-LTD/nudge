import type { Permission } from '@marketing/types';

/** Permissions by the area they cover, each with a short name for summaries. */
export const PERMISSION_GROUPS: {
    title: string;
    permissions: [Permission, string][];
}[] = [
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
    return PERMISSION_GROUPS.flatMap((group) => {
        const granted = group.permissions
            .filter(([permission]) => permissions.includes(permission))
            .map(([, short]) => short);

        return granted.length === 0
            ? []
            : [`${group.title}: ${granted.join(', ')}`];
    });
}

/** The areas permissions are grouped into, in order. */
export const PERMISSION_AREAS = PERMISSION_GROUPS.map((group) => group.title);

/**
 * How much of each area a set of permissions covers: everything, nothing, or the short names of what it grants.
 */
export function permissionLevels(
    permissions: Permission[],
): { area: string; level: 'full' | 'none' | 'some'; label: string }[] {
    return PERMISSION_GROUPS.map((group) => {
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

const inOrder = (next: Set<Permission>, order: Permission[]): Permission[] =>
    order.filter((permission) => next.has(permission));

/**
 * Ticks or unticks one permission. Ticking also ticks what it needs, and unticking a permission that others
 * need unticks those too. The result follows `order`.
 */
export function togglePermission(
    value: Permission[],
    permission: Permission,
    on: boolean,
    order: Permission[],
): Permission[] {
    const next = new Set(value);

    if (on) {
        next.add(permission);

        const needed = NEEDS[permission];

        if (needed) {
            next.add(needed);
        }
    } else {
        next.delete(permission);

        for (const [dependent, needed] of Object.entries(NEEDS)) {
            if (needed === permission) {
                next.delete(dependent as Permission);
            }
        }
    }

    return inOrder(next, order);
}

/** Ticks or unticks a whole group. Turning it on also adds whatever its permissions need. */
export function toggleGroup(
    value: Permission[],
    permissions: Permission[],
    on: boolean,
    order: Permission[],
): Permission[] {
    const next = new Set(value);

    for (const permission of permissions) {
        if (on) {
            next.add(permission);

            const needed = NEEDS[permission];

            if (needed) {
                next.add(needed);
            }
        } else {
            next.delete(permission);
        }
    }

    return inOrder(next, order);
}

/** How much of a group a set of permissions covers, with a label: "All", "None" or "2 of 4". */
export function groupCoverage(
    permissions: Permission[],
    group: Permission[],
): { granted: number; total: number; label: string } {
    const granted = group.filter((permission) =>
        permissions.includes(permission),
    ).length;
    const total = group.length;

    return {
        granted,
        total,
        label:
            granted === total
                ? 'All'
                : granted === 0
                  ? 'None'
                  : `${granted} of ${total}`,
    };
}

/** How many permissions differ between two sets. */
export function countChanges(
    before: Permission[],
    after: Permission[],
): number {
    return (
        before.filter((permission) => !after.includes(permission)).length +
        after.filter((permission) => !before.includes(permission)).length
    );
}
