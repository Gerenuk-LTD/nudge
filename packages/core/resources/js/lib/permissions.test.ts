import { describe, expect, it } from 'vitest';
import type { Permission } from '@nudge/types';
import {
    PERMISSION_GROUPS,
    countChanges,
    groupCoverage,
    togglePermission,
    toggleGroup,
} from './permissions';

const order = PERMISSION_GROUPS.flatMap((group) =>
    group.permissions.map(([permission]) => permission),
);
const campaigns = PERMISSION_GROUPS[1].permissions.map(
    ([permission]) => permission,
);

describe('togglePermission', () => {
    it('adds what a permission needs when ticking it', () => {
        expect(togglePermission([], 'contacts.manage', true, order)).toEqual([
            'contacts.view',
            'contacts.manage',
        ]);
    });

    it('removes what relies on a permission when unticking it', () => {
        const value: Permission[] = [
            'campaigns.view',
            'campaigns.manage',
            'campaigns.send',
            'campaigns.approve',
            'contacts.view',
        ];

        expect(togglePermission(value, 'campaigns.view', false, order)).toEqual(
            ['contacts.view'],
        );
    });
});

describe('toggleGroup', () => {
    it('turns on every permission in the group', () => {
        expect(toggleGroup([], campaigns, true, order)).toEqual(campaigns);
    });

    it('adds what a permission outside the group needs', () => {
        expect(toggleGroup([], ['contacts.manage'], true, order)).toEqual([
            'contacts.view',
            'contacts.manage',
        ]);
    });

    it('turns the group off', () => {
        expect(toggleGroup(campaigns, campaigns, false, order)).toEqual([]);
    });
});

describe('groupCoverage', () => {
    it('says All, None or how many', () => {
        expect(groupCoverage(campaigns, campaigns).label).toBe('All');
        expect(groupCoverage([], campaigns).label).toBe('None');
        expect(
            groupCoverage(['campaigns.view', 'campaigns.send'], campaigns),
        ).toEqual({ granted: 2, total: 4, label: '2 of 4' });
    });
});

describe('countChanges', () => {
    it('counts what was added and removed', () => {
        expect(
            countChanges(
                ['contacts.view', 'contacts.manage'],
                ['contacts.manage', 'campaigns.view'],
            ),
        ).toBe(2);
    });
});
