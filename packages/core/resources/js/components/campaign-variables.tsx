import { ChevronDown, CircleHelp } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/** The campaign's own tags and their values, as compact rows that fold away once all are filled. */
export function CampaignVariables({
    variables,
    values,
    onChange,
    errors,
}: {
    variables: { tag: string; optional: boolean }[];
    values: Record<string, string>;
    onChange: (values: Record<string, string>) => void;
    errors: Record<string, string | undefined>;
}) {
    const isMissing = (tag: string, optional: boolean) =>
        !optional && !(values[tag] ?? '').trim();
    const missing = variables.filter(({ tag, optional }) =>
        isMissing(tag, optional),
    );
    const anyMissing = missing.length > 0;
    const [open, setOpen] = useState(anyMissing);

    // Opens when a value is needed, but never closes by itself, so the card doesn't jump while someone types.
    useEffect(() => {
        if (anyMissing) {
            setOpen(true);
        }
    }, [anyMissing]);

    return (
        <Collapsible open={open} onOpenChange={setOpen} asChild>
            <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                <div
                    className={cn(
                        'flex items-center justify-between gap-3 px-5 py-3',
                        open && 'border-b',
                    )}
                >
                    <div className="flex items-center gap-1.5 text-sm font-semibold">
                        Variables
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    aria-label="About variables"
                                >
                                    <CircleHelp className="size-3.5 text-muted-foreground" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-64">
                                Values that are the same for everyone in this
                                campaign. Any tag that isn't a contact detail or
                                custom field becomes a variable.
                            </TooltipContent>
                        </Tooltip>
                    </div>
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="flex items-center gap-2"
                        >
                            {anyMissing ? (
                                <span className="text-13 font-medium text-amber-700 dark:text-amber-400">
                                    {missing.length} of {variables.length} need
                                    a value
                                </span>
                            ) : (
                                <span className="text-13 text-muted-foreground">
                                    {variables.length} set
                                </span>
                            )}
                            <ChevronDown
                                className={cn(
                                    'size-4 text-muted-foreground transition-transform',
                                    open && 'rotate-180',
                                )}
                            />
                        </button>
                    </CollapsibleTrigger>
                </div>
                <CollapsibleContent>
                    <div className="max-h-[26rem] divide-y overflow-y-auto">
                        {variables.map(({ tag, optional }) => (
                            <div
                                key={tag}
                                className="grid items-center gap-x-4 gap-y-1.5 px-5 py-2.5 sm:grid-cols-[minmax(0,13rem)_minmax(0,1fr)]"
                            >
                                <label
                                    htmlFor={`variable-${tag}`}
                                    className="flex min-w-0 items-center gap-2"
                                >
                                    <span
                                        className="truncate font-mono text-13"
                                        title={`{{ ${tag} }}`}
                                    >
                                        {`{{ ${tag} }}`}
                                    </span>
                                    {optional ? (
                                        <span className="text-xs text-muted-foreground">
                                            optional
                                        </span>
                                    ) : (
                                        isMissing(tag, optional) && (
                                            <>
                                                <span
                                                    className="size-1.5 shrink-0 rounded-full bg-amber-500"
                                                    aria-hidden
                                                />
                                                <span className="sr-only">
                                                    needs a value
                                                </span>
                                            </>
                                        )
                                    )}
                                </label>
                                <div>
                                    <Input
                                        id={`variable-${tag}`}
                                        className="h-8 text-13"
                                        value={values[tag] ?? ''}
                                        aria-invalid={Boolean(
                                            errors[`variables.${tag}`],
                                        )}
                                        onChange={(event) =>
                                            onChange({
                                                ...values,
                                                [tag]: event.target.value,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={errors[`variables.${tag}`]}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                    {errors.variables && (
                        <div className="border-t px-5 py-2.5">
                            <InputError message={errors.variables} />
                        </div>
                    )}
                </CollapsibleContent>
            </section>
        </Collapsible>
    );
}
