import { Check, Plus } from 'lucide-react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { cn } from '@/lib/utils';
import { ToggleSwitch } from '@marketing/components/toggle-switch';
import type { CustomField, Option } from '@marketing/types';

export type CustomFieldValue = string | number | boolean | null;

/** How a contact came to be in the workspace, phrased to follow "Added 7 Aug". */
export const contactSources: Record<string, string> = {
    manual: 'by hand',
    api: 'through the API',
    import: 'from an import',
};

/**
 * A round badge with a contact's initials, from their name or else their email.
 */
export function ContactAvatar({
    name,
    email,
    className,
}: {
    name: string | null;
    email: string;
    className?: string;
}) {
    const words = (name ?? '').split(/\s+/u).filter(Boolean);
    const initials = (
        words.length > 0
            ? words
                  .slice(0, 2)
                  .map((word) => Array.from(word)[0])
                  .join('')
            : (Array.from(email)[0] ?? '?')
    ).toUpperCase();

    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex size-8 shrink-0 items-center justify-center rounded-full border bg-muted text-xs font-medium text-muted-foreground',
                className,
            )}
        >
            {initials}
        </span>
    );
}

/**
 * The workspace's lists as pills to switch on and off, so every list and whether the contact is on it shows at once.
 */
export function ListPicker({
    lists,
    value,
    onChange,
}: {
    lists: Option[];
    value: number[];
    onChange: (lists: number[]) => void;
}) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {lists.map((list) => {
                const selected = value.includes(list.id);

                return (
                    <button
                        key={list.id}
                        type="button"
                        aria-pressed={selected}
                        onClick={() =>
                            onChange(
                                selected
                                    ? value.filter((id) => id !== list.id)
                                    : [...value, list.id],
                            )
                        }
                        className={cn(
                            'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-13 transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-60',
                            selected
                                ? 'border-foreground bg-foreground text-background'
                                : 'bg-card text-muted-foreground hover:border-foreground/30 hover:text-foreground',
                        )}
                    >
                        {/* Both states carry an icon, so selecting a list never changes its width and moves the rest. */}
                        {selected ? (
                            <Check className="size-3.5" />
                        ) : (
                            <Plus className="size-3.5" />
                        )}
                        {list.name}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * One custom field, with the input that suits its type.
 */
export function CustomFieldInput({
    field,
    value,
    onChange,
    error,
    idPrefix = 'custom',
}: {
    field: CustomField;
    value: CustomFieldValue;
    onChange: (value: CustomFieldValue) => void;
    error?: string;
    idPrefix?: string;
}) {
    const id = `${idPrefix}-${field.key}`;

    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{field.label}</Label>
            {field.type === 'boolean' ? (
                <NativeSelect
                    id={id}
                    value={value === null ? '' : value ? '1' : '0'}
                    onChange={(event) =>
                        onChange(
                            event.target.value === ''
                                ? null
                                : event.target.value === '1',
                        )
                    }
                >
                    <option value="">Not set</option>
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </NativeSelect>
            ) : (
                <Input
                    id={id}
                    type={
                        field.type === 'number'
                            ? 'number'
                            : field.type === 'date'
                              ? 'date'
                              : 'text'
                    }
                    step={field.type === 'number' ? 'any' : undefined}
                    value={value === null ? '' : String(value)}
                    onChange={(event) =>
                        onChange(
                            event.target.value === ''
                                ? null
                                : event.target.value,
                        )
                    }
                />
            )}
            <InputError message={error} />
        </div>
    );
}

/**
 * Whether the contact gets marketing emails, as a switch with the consent reminder beside it.
 */
export function SubscriptionSwitch({
    id,
    subscribed,
    onChange,
    note,
}: {
    id: string;
    subscribed: boolean;
    onChange: (subscribed: boolean) => void;
    note?: string;
}) {
    return (
        <div className="flex items-start justify-between gap-4 rounded-lg border bg-muted/30 px-3.5 py-3">
            <div className="space-y-0.5">
                <Label htmlFor={id} className="text-sm">
                    Subscribed to marketing emails
                </Label>
                <p className="text-xs text-muted-foreground">
                    {note ??
                        'Only if they agreed to hear from you. Unsubscribed contacts are kept but never emailed.'}
                </p>
            </div>
            <span className="pt-0.5">
                <ToggleSwitch
                    id={id}
                    checked={subscribed}
                    onCheckedChange={onChange}
                />
            </span>
        </div>
    );
}
