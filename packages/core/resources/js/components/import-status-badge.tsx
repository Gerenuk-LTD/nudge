import { StatusBadge } from '@marketing/components/status-badge';
import type { StatusTone } from '@marketing/components/status-badge';
import type { ImportStatus } from '@marketing/types';

const statuses: Record<ImportStatus, { label: string; tone: StatusTone }> = {
    pending: { label: 'Needs mapping', tone: 'warning' },
    queued: { label: 'Queued', tone: 'neutral' },
    processing: { label: 'Importing', tone: 'info' },
    completed: { label: 'Completed', tone: 'success' },
    failed: { label: 'Failed', tone: 'danger' },
};

export function ImportStatusBadge({ status }: { status: ImportStatus }) {
    return (
        <StatusBadge
            tone={statuses[status].tone}
            live={status === 'processing'}
        >
            {statuses[status].label}
        </StatusBadge>
    );
}
