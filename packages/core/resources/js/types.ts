export type Permission =
    | 'contacts.view'
    | 'contacts.manage'
    | 'contacts.import'
    | 'segments.manage'
    | 'campaigns.view'
    | 'campaigns.manage'
    | 'campaigns.send'
    | 'campaigns.approve'
    | 'providers.manage'
    | 'automations.view'
    | 'automations.manage'
    | 'members.manage'
    | 'roles.manage'
    | 'api_tokens.manage'
    | 'workspace.manage';

export type WorkspaceSharedProps = {
    current: { id: number; name: string };
    all: { id: number; name: string }[];
    /** The signed-in user's role in the current workspace, such as "Owner". */
    role: string | null;
    permissions: Permission[];
    /** The most pressing reason campaigns can't go out, or null when sending is healthy. */
    sendingIssue: 'paused' | 'no_provider' | 'no_postal_address' | null;
    canCreateWorkspace: boolean;
};

export type Option = { id: number; name: string };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type ContactStatus = 'subscribed' | 'unsubscribed';

export type CustomFieldType = 'text' | 'number' | 'date' | 'boolean';

export type CustomField = {
    key: string;
    label: string;
    type: CustomFieldType;
};

export type SegmentFieldType =
    | 'text'
    | 'number'
    | 'date'
    | 'boolean'
    | 'choice'
    | 'tag'
    | 'list'
    | 'activity';

export type SegmentField = {
    key: string;
    label: string;
    type: SegmentFieldType;
    operators: string[];
    options: { value: string | number; label: string }[];
};

export type SegmentCondition = {
    type: 'condition';
    field: string;
    operator: string;
    value?: string | number | null;
};

export type SegmentGroup = {
    match: 'all' | 'any';
    conditions: SegmentNode[];
};

/** A group inside another group; only nested groups carry a type. */
export type SegmentNestedGroup = SegmentGroup & { type: 'group' };

export type SegmentNode = SegmentCondition | SegmentNestedGroup;

export type ImportStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'completed'
    | 'failed';

export type ImportSummary = {
    id: number;
    original_filename: string;
    status: ImportStatus;
    total_rows: number;
    processed_rows: number;
    created_count: number;
    updated_count: number;
    failed_count: number;
    created_at: string;
    finished_at: string | null;
};

export type CredentialField = {
    key: string;
    label: string;
    secret: boolean;
    required: boolean;
    default: string | null;
    options: { value: string; label: string }[];
    help: string | null;
};

export type WebhookVerification =
    | 'none'
    | 'signing_secret'
    | 'basic_auth'
    | 'sns';

export type ProviderTypeDefinition = {
    value: string;
    label: string;
    fields: CredentialField[];
    webhook_verification: WebhookVerification;
    webhook_secret_label: string | null;
};

export type CampaignStatus =
    | 'draft'
    | 'scheduled'
    | 'sending'
    | 'sent'
    | 'cancelled';

export type CampaignStats = {
    recipients: number;
    sent: number;
    failed: number;
    delivered: number;
    bounced: number;
    complained: number;
    opened: number;
    clicked: number;
    unsubscribed: number;
};

export type CampaignSummary = {
    id: number;
    name: string;
    subject: string | null;
    status: CampaignStatus;
    scheduled_at: string | null;
    sent_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    stats: CampaignStats;
    track_opens: boolean;
    track_clicks: boolean;
    kind: 'broadcast' | 'automation';
    automation_id: number | null;
};

export type ChecklistItem = {
    key: string;
    label: string;
    passed: boolean;
    blocking: boolean;
    message: string | null;
};

export type StepType =
    | 'send_email'
    | 'wait'
    | 'condition'
    | 'update_contact'
    | 'webhook'
    | 'exit';

export type AutomationStep = {
    id: string;
    type: StepType;
    config: Record<string, unknown>;
    yes?: AutomationStep[];
    no?: AutomationStep[];
};

export type StepStats = {
    waiting?: number;
    campaign_id?: number;
    sent?: number;
    opened?: number;
    clicked?: number;
    unsubscribed?: number;
    bounced?: number;
};

/** A template an email can start from, keyed `starter:<name>` for built-ins or `saved:<id>` for the workspace's own. */
export type TemplateOption = {
    key: string;
    name: string;
    description: string | null;
    subject: string | null;
    preheader: string | null;
    editor: string;
    editor_state: unknown;
    html: string | null;
    saved: boolean;
};
