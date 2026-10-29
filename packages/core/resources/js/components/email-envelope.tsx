import { Braces } from 'lucide-react';
import { useRef } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { MergeTag } from '@marketing/components/email-editors';

export type EnvelopeRow = {
    name: string;
    label: string;
    /** Custom controls for the row, in place of a single text input. */
    content?: ReactNode;
    value?: string;
    onChange?: (value: string) => void;
    placeholder?: string;
    error?: string;
    /** Offers fields such as first name to insert where the cursor is. */
    mergeTags?: MergeTag[];
    muted?: boolean;
};

/** The look of a borderless field inside the envelope, for custom row controls to match. */
export const envelopeInput =
    'h-10 min-w-0 bg-transparent text-sm outline-none placeholder:text-muted-foreground/60 disabled:cursor-not-allowed disabled:opacity-60';

/**
 * The top of an email as an inbox shows it, such as the subject and preview text, edited in place as one card above
 * the content rather than as separate form fields.
 */
export function EmailEnvelope({ rows }: { rows: EnvelopeRow[] }) {
    return (
        <div className="divide-y overflow-hidden rounded-xl border bg-card shadow-xs">
            {rows.map((row) => (
                <EnvelopeLine key={row.name} row={row} />
            ))}
        </div>
    );
}

function EnvelopeLine({ row }: { row: EnvelopeRow }) {
    const input = useRef<HTMLInputElement>(null);
    const id = `envelope-${row.name}`;

    const value = row.value ?? '';

    const insert = (tag: string) => {
        const element = input.current;
        const start = element?.selectionStart ?? value.length;
        const end = element?.selectionEnd ?? value.length;

        row.onChange?.(value.slice(0, start) + tag + value.slice(end));
        requestAnimationFrame(() => {
            element?.focus();
            element?.setSelectionRange(start + tag.length, start + tag.length);
        });
    };

    return (
        <div className="px-4 py-1 sm:px-5">
            <div className="flex items-center gap-3">
                <label
                    htmlFor={row.content ? undefined : id}
                    className="w-24 shrink-0 text-13 text-muted-foreground"
                >
                    {row.label}
                </label>
                {row.content ?? (
                    <input
                        ref={input}
                        id={id}
                        value={value}
                        placeholder={row.placeholder}
                        aria-invalid={row.error ? true : undefined}
                        onChange={(event) => row.onChange?.(event.target.value)}
                        className={cn(
                            envelopeInput,
                            'flex-1',
                            row.muted
                                ? 'text-muted-foreground focus:text-foreground'
                                : 'font-medium placeholder:font-normal',
                        )}
                    />
                )}
                {row.mergeTags && row.mergeTags.length > 0 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 shrink-0 gap-1.5 px-2 text-13 text-muted-foreground"
                            >
                                <Braces />
                                Insert field
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            onCloseAutoFocus={(event) => event.preventDefault()}
                        >
                            {row.mergeTags.map((tag) => (
                                <DropdownMenuItem
                                    key={tag.tag}
                                    onSelect={() => insert(tag.tag)}
                                >
                                    {tag.label}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
            <InputError message={row.error} className="pb-2 sm:pl-27" />
        </div>
    );
}
