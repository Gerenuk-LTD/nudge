import { cn } from '@/lib/utils';

/**
 * An on/off switch for settings that take effect as a whole, such as tracking. Label it with `aria-label` or by
 * pointing a label's `htmlFor` at its `id`.
 */
export function ToggleSwitch({
    checked,
    onCheckedChange,
    id,
    disabled = false,
    'aria-label': ariaLabel,
}: {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    id?: string;
    disabled?: boolean;
    'aria-label'?: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            id={id}
            aria-checked={checked}
            aria-label={ariaLabel}
            disabled={disabled}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border border-transparent transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-foreground' : 'bg-input',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'pointer-events-none block size-4 rounded-full bg-background shadow-sm transition-transform',
                    checked ? 'translate-x-4' : 'translate-x-0.5',
                )}
            />
        </button>
    );
}
