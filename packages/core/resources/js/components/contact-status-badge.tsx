import { StatusBadge } from '@marketing/components/status-badge';
import type { ContactStatus } from '@marketing/types';

/** Whether a contact can be emailed: subscribed or unsubscribed. */
export function ContactStatusBadge({ status }: { status: ContactStatus }) {
    return (
        <StatusBadge tone={status === 'subscribed' ? 'success' : 'neutral'}>
            {status === 'subscribed' ? 'Subscribed' : 'Unsubscribed'}
        </StatusBadge>
    );
}
