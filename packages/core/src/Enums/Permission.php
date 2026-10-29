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
            self::ContactsView => 'View contacts, lists, tags and segments',
            self::ContactsManage => 'Create, edit and archive contacts, lists, tags and custom fields',
            self::ContactsImport => 'Import contacts from CSV',
            self::SegmentsManage => 'Create and edit segments',
            self::CampaignsView => 'View campaigns and their reports',
            self::CampaignsManage => 'Create and edit draft campaigns and email templates',
            self::CampaignsSend => 'Send and schedule campaigns',
            self::CampaignsApprove => 'Approve campaigns when approval is required',
            self::ProvidersManage => 'Connect sending providers and change sending settings',
            self::AutomationsView => 'View automations and their reports',
            self::AutomationsManage => 'Create, edit, start and stop automations',
            self::MembersManage => 'Invite and remove members',
            self::RolesManage => 'Create and edit roles',
            self::ApiTokensManage => 'Create and revoke API tokens',
            self::WorkspaceManage => 'Rename the workspace',
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
