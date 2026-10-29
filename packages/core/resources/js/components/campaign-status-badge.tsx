import { StatusBadge } from '@marketing/components/status-badge';
import type { StatusTone } from '@marketing/components/status-badge';
import type { CampaignStatus } from '@marketing/types';

const statuses: Record<CampaignStatus, { label: string; tone: StatusTone }> = {
    draft: { label: 'Draft', tone: 'neutral' },
    scheduled: { label: 'Scheduled', tone: 'info' },
    sending: { label: 'Sending', tone: 'warning' },
    sent: { label: 'Sent', tone: 'success' },
    cancelled: { label: 'Cancelled', tone: 'danger' },
};

export function CampaignStatusBadge({ status }: { status: CampaignStatus }) {
    return (
        <StatusBadge tone={statuses[status].tone} live={status === 'sending'}>
            {statuses[status].label}
        </StatusBadge>
    );
}
