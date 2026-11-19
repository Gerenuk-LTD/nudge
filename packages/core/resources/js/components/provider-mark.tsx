import { cn } from '@/lib/utils';
import type { StatusTone } from '@nudge/components/status-badge';

/**
 * A neutral tile with a provider's initials. No provider's logo is shown, so none is favoured over the others.
 */
export function ProviderMark({
    label,
    className,
}: {
    label: string;
    className?: string;
}) {
    const initials = label
        .replace(/\(.*\)/, '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase();

    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex size-9 shrink-0 items-center justify-center rounded-lg border bg-muted/40 text-xs font-semibold text-muted-foreground',
                className,
            )}
        >
            {initials}
        </span>
    );
}

/**
 * Whether a connection is working: used, waiting on its first webhook, or not used yet.
 */
export function providerHealth(provider: {
    receives_webhooks: boolean;
    last_sent_at: string | null;
    last_webhook_at: string | null;
}): { tone: StatusTone; label: string } {
    if (
        provider.receives_webhooks &&
        provider.last_sent_at !== null &&
        provider.last_webhook_at === null
    ) {
        return { tone: 'warning', label: 'Waiting for webhooks' };
    }

    return provider.last_sent_at
        ? { tone: 'success', label: 'Working' }
        : { tone: 'neutral', label: 'Not used yet' };
}

/** Bounce rates above these hurt deliverability; providers start warning at around 5%. */
export const BOUNCE_WARNING = 0.02;
export const BOUNCE_DANGER = 0.05;
