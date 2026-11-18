import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { ToggleSwitch } from '@nudge/components/toggle-switch';

/** A setting that is on or off: a label and explanation beside a switch, in a light inset box. */
export function ToggleRow({
    id,
    label,
    description,
    checked,
    onCheckedChange,
    disabled,
    children,
}: {
    id: string;
    label: ReactNode;
    description?: ReactNode;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    /** Extra notes or an error under the description. */
    children?: ReactNode;
}) {
    return (
        <div className="flex items-start justify-between gap-4 rounded-lg border bg-muted/30 px-3.5 py-3">
            <div className="space-y-0.5">
                <Label htmlFor={id} className="text-sm">
                    {label}
                </Label>
                {description && (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
                {children}
            </div>
            <span className="pt-0.5">
                <ToggleSwitch
                    id={id}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={onCheckedChange}
                />
            </span>
        </div>
    );
}
