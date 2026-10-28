export type Permission =
    | 'contacts.view'
    | 'contacts.manage'
    | 'contacts.import'
    | 'segments.manage'
    | 'members.manage'
    | 'roles.manage'
    | 'api_tokens.manage'
    | 'workspace.manage';

export type WorkspaceSharedProps = {
    current: { id: number; name: string };
    all: { id: number; name: string }[];
    permissions: Permission[];
};

export type Option = { id: number; name: string };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
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
    | 'list';

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
