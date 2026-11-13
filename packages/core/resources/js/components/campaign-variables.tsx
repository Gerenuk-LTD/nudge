import InputError from '@/components/input-error';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { envelopeInput } from '@marketing/components/email-envelope';

/** Turns a variable's tag into a label people read: promo_code → Promo code. */
function labelFor(tag: string): string {
    const words = tag.replace(/[._]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** The campaign's own tags and their values, laid out like the rest of the Content step. */
export function CampaignVariables({
    variables,
    values,
    onChange,
    errors,
}: {
    variables: { tag: string; optional: boolean; fallback: string | null }[];
    values: Record<string, string>;
    onChange: (values: Record<string, string>) => void;
    errors: Record<string, string | undefined>;
}) {
    const missing = variables.filter(
        ({ tag, fallback }) => fallback === null && !(values[tag] ?? '').trim(),
    );

    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <header className="flex items-start justify-between gap-4 border-b px-5 py-4 sm:px-6">
                <div className="space-y-1">
                    <h2 className="text-base font-semibold">Variables</h2>
                    <p className="text-sm text-muted-foreground">
                        Filled in the same for everyone who gets this campaign.
                    </p>
                </div>
                {missing.length > 0 && (
                    <span className="shrink-0 pt-0.5 text-13 font-medium text-amber-700 dark:text-amber-400">
                        {missing.length} to fill in
                    </span>
                )}
            </header>
            <div className="max-h-[30rem] divide-y overflow-y-auto">
                {variables.map(({ tag, fallback }) => (
                    <div key={tag} className="px-4 py-1 sm:px-6">
                        <div className="flex items-center gap-3">
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <label
                                        htmlFor={`variable-${tag}`}
                                        className="w-32 shrink-0 truncate text-13 text-muted-foreground sm:w-40"
                                    >
                                        {labelFor(tag)}
                                    </label>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="top"
                                    className="font-mono text-xs"
                                >{`{{ ${tag} }}`}</TooltipContent>
                            </Tooltip>
                            <input
                                id={`variable-${tag}`}
                                value={values[tag] ?? ''}
                                placeholder={
                                    fallback !== null
                                        ? `${fallback} if left empty`
                                        : 'Needed before sending'
                                }
                                aria-invalid={
                                    errors[`variables.${tag}`]
                                        ? true
                                        : undefined
                                }
                                onChange={(event) =>
                                    onChange({
                                        ...values,
                                        [tag]: event.target.value,
                                    })
                                }
                                className={cn(
                                    envelopeInput,
                                    'flex-1 font-medium placeholder:font-normal',
                                    fallback === null &&
                                        'placeholder:text-amber-700/70 dark:placeholder:text-amber-400/70',
                                )}
                            />
                        </div>
                        <InputError
                            message={errors[`variables.${tag}`]}
                            className="pb-2 pl-35 sm:pl-43"
                        />
                    </div>
                ))}
            </div>
            {errors.variables && (
                <InputError
                    message={errors.variables}
                    className="border-t px-5 py-2.5 sm:px-6"
                />
            )}
        </section>
    );
}
