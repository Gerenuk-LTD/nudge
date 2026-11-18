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
import { home } from '@/routes/marketing';
import { index as automationsIndex } from '@/routes/marketing/automations';
import { index as campaignsIndex } from '@/routes/marketing/campaigns';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import { index as customFieldsIndex } from '@/routes/marketing/custom-fields';
import { index as importsIndex } from '@/routes/marketing/imports';
import { index as listsIndex } from '@/routes/marketing/lists';
import { index as providersIndex } from '@/routes/marketing/providers';
import { index as segmentsIndex } from '@/routes/marketing/segments';
import { index as suppressionsIndex } from '@/routes/marketing/suppressions';
import { index as tagsIndex } from '@/routes/marketing/tags';
import { index as templatesIndex } from '@/routes/marketing/templates';
import type { Permission } from '@marketing/types';

export type MarketingNavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
    permission: Permission;
    group: string;
};

/**
 * The core's sidebar items, shown under their group's heading in this order. Host apps render these and can add their own, such as billing.
 */
export const marketingNavItems: MarketingNavItem[] = [
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
