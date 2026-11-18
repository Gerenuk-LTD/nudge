<?php

namespace Gerenuk\Marketing\Enums;

enum Permission: string
{
    case ContactsView = 'contacts.view';
    case ContactsManage = 'contacts.manage';
    case ContactsImport = 'contacts.import';
    case SegmentsManage = 'segments.manage';
    case CampaignsView = 'campaigns.view';
    case CampaignsManage = 'campaigns.manage';
    case CampaignsSend = 'campaigns.send';
    case CampaignsApprove = 'campaigns.approve';
    case ProvidersManage = 'providers.manage';
    case AutomationsView = 'automations.view';
    case AutomationsManage = 'automations.manage';
    case MembersManage = 'members.manage';
    case RolesManage = 'roles.manage';
    case ApiTokensManage = 'api_tokens.manage';
    case WorkspaceManage = 'workspace.manage';

    public function label(): string
    {
        return match ($this) {
            self::ContactsView => 'View contacts',
            self::ContactsManage => 'Manage contacts',
            self::ContactsImport => 'Import contacts',
            self::SegmentsManage => 'Manage segments',
            self::CampaignsView => 'View campaigns',
            self::CampaignsManage => 'Edit campaigns',
            self::CampaignsSend => 'Send campaigns',
            self::CampaignsApprove => 'Approve campaigns',
            self::ProvidersManage => 'Manage sending',
            self::AutomationsView => 'View automations',
            self::AutomationsManage => 'Manage automations',
            self::MembersManage => 'Manage members',
            self::RolesManage => 'Manage roles',
            self::ApiTokensManage => 'Manage API tokens',
            self::WorkspaceManage => 'Rename workspace',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ContactsView => 'See contacts, lists, tags, custom fields, segments and suppressions.',
            self::ContactsManage => 'Add, edit, archive and unsubscribe contacts, and edit lists, tags, custom fields and suppressions.',
            self::ContactsImport => 'Upload CSV files of contacts and see past imports.',
            self::SegmentsManage => 'Create, edit and delete segments.',
            self::CampaignsView => 'See campaigns, their reports and email templates.',
            self::CampaignsManage => 'Create, edit, duplicate, test and delete draft campaigns, resend to non-openers, and manage email templates.',
            self::CampaignsSend => 'Schedule, unschedule and cancel campaigns, and pick A/B test winners.',
            self::CampaignsApprove => 'Approve campaigns and automations when the workspace requires approval.',
            self::ProvidersManage => 'Connect and test sending providers, and change sending settings.',
            self::AutomationsView => 'See automations and their reports.',
            self::AutomationsManage => 'Create, edit, start, pause and delete automations.',
            self::MembersManage => 'Invite people, remove members and change their roles.',
            self::RolesManage => 'Create, edit and delete roles. Nobody can give out a permission they don\'t have.',
            self::ApiTokensManage => 'Create and revoke API tokens, and see the API log. A token can only do what its creator can.',
            self::WorkspaceManage => 'Change the workspace\'s name.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
