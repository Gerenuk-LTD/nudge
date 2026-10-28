import { Badge } from '@/components/ui/badge';
import type { CampaignStatus } from '@marketing/types';

const labels: Record<CampaignStatus, string> = {
    draft: 'Draft',
    scheduled: 'Scheduled',
    sending: 'Sending',
    sent: 'Sent',
    cancelled: 'Cancelled',
};

export function CampaignStatusBadge({ status }: { status: CampaignStatus }) {
    return (
        <Badge
            variant={
                status === 'sent'
                    ? 'secondary'
                    : status === 'cancelled'
                      ? 'destructive'
                      : 'outline'
            }
        >
            {labels[status]}
        </Badge>
    );
}
