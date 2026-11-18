import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Ban,
    House,
    LayoutTemplate,
    FileUp,
    Filter,
    List,
    Mail,
    Send,
    Workflow,
    Tag,
    TextCursorInput,
    Users,
} from 'lucide-react';
import { home } from '@/routes/nudge';
import { index as automationsIndex } from '@/routes/nudge/automations';
import { index as campaignsIndex } from '@/routes/nudge/campaigns';
import { index as contactsIndex } from '@/routes/nudge/contacts';
import { index as customFieldsIndex } from '@/routes/nudge/custom-fields';
import { index as importsIndex } from '@/routes/nudge/imports';
import { index as listsIndex } from '@/routes/nudge/lists';
import { index as providersIndex } from '@/routes/nudge/providers';
import { index as segmentsIndex } from '@/routes/nudge/segments';
import { index as suppressionsIndex } from '@/routes/nudge/suppressions';
import { index as tagsIndex } from '@/routes/nudge/tags';
import { index as templatesIndex } from '@/routes/nudge/templates';
import type { Permission } from '@nudge/types';

export type NudgeNavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
    permission: Permission;
    group: string;
};

/**
 * The core's sidebar items, shown under their group's heading in this order. Host apps render these and can add their own, such as billing.
 */
export const nudgeNavItems: NudgeNavItem[] = [
    {
        title: 'Home',
        href: home(),
        icon: House,
        permission: 'contacts.view',
        group: '',
    },
    {
        title: 'Campaigns',
        href: campaignsIndex(),
        icon: Send,
        permission: 'campaigns.view',
        group: 'Messaging',
    },
    {
        title: 'Automations',
        href: automationsIndex(),
        icon: Workflow,
        permission: 'automations.view',
        group: 'Messaging',
    },
    {
        title: 'Templates',
        href: templatesIndex(),
        icon: LayoutTemplate,
        permission: 'campaigns.view',
        group: 'Messaging',
    },
    {
        title: 'Contacts',
        href: contactsIndex(),
        icon: Users,
        permission: 'contacts.view',
        group: 'Audience',
    },
    {
        title: 'Segments',
        href: segmentsIndex(),
        icon: Filter,
        permission: 'contacts.view',
        group: 'Audience',
    },
    {
        title: 'Lists',
        href: listsIndex(),
        icon: List,
        permission: 'contacts.view',
        group: 'Audience',
    },
    {
        title: 'Tags',
        href: tagsIndex(),
        icon: Tag,
        permission: 'contacts.view',
        group: 'Audience',
    },
    {
        title: 'Custom fields',
        href: customFieldsIndex(),
        icon: TextCursorInput,
        permission: 'contacts.view',
        group: 'Audience',
    },
    {
        title: 'Imports',
        href: importsIndex(),
        icon: FileUp,
        permission: 'contacts.import',
        group: 'Audience',
    },
    {
        title: 'Suppressions',
        href: suppressionsIndex(),
        icon: Ban,
        permission: 'contacts.view',
        group: 'Deliverability',
    },
    {
        title: 'Providers',
        href: providersIndex(),
        icon: Mail,
        permission: 'providers.manage',
        group: 'Deliverability',
    },
];
