import { StatusBadge } from '@nudge/components/status-badge';
import type { ContactStatus } from '@nudge/types';

/** Whether a contact can be emailed: subscribed or unsubscribed. */
export function ContactStatusBadge({ status }: { status: ContactStatus }) {
    return (
        <StatusBadge tone={status === 'subscribed' ? 'success' : 'neutral'}>
            {status === 'subscribed' ? 'Subscribed' : 'Unsubscribed'}
        </StatusBadge>
    );
}
