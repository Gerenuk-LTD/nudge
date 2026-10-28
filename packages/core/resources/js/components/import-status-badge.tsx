import { Badge } from '@/components/ui/badge';
import type { ImportStatus } from '@marketing/types';

const statusLabels: Record<ImportStatus, string> = {
    pending: 'Needs mapping',
    queued: 'Queued',
    processing: 'Importing',
    completed: 'Completed',
    failed: 'Failed',
};

export function ImportStatusBadge({ status }: { status: ImportStatus }) {
    return (
        <Badge
            variant={
                status === 'failed'
                    ? 'destructive'
                    : status === 'completed'
                      ? 'secondary'
                      : 'outline'
            }
        >
            {statusLabels[status]}
        </Badge>
    );
}
