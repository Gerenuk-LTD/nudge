import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@nudge/components/status-badge';

/** Turns a variable's tag into a label people read: promo_code → Promo code. */
function labelFor(tag: string): string {
    const words = tag.replace(/[._]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * The tags in this email that aren't contact details, such as `{{ promo_code }}`, each with the one value
 * everyone gets. Laid out like the Options card below it: a row per variable, named on the left.
 */
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
    const isEmpty = (tag: string) => !(values[tag] ?? '').trim();
    const missing = variables.filter(
        ({ tag, fallback }) => fallback === null && isEmpty(tag),
    ).length;

    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <header className="flex items-start justify-between gap-4 border-b px-5 py-4 sm:px-6">
                <div className="space-y-1">
                    <h2 className="text-base font-semibold">Variables</h2>
                    <p className="text-sm text-muted-foreground">
                        Tags in this email that aren't contact details. Each
                        gets one value, the same for everyone.
                    </p>
                </div>
                <div className="shrink-0 pt-0.5">
                    {missing > 0 ? (
                        <StatusBadge tone="warning">
                            {missing} to fill in
                        </StatusBadge>
                    ) : (
                        <StatusBadge tone="success">All filled in</StatusBadge>
                    )}
                </div>
            </header>

            <div className="divide-y">
                {variables.map(({ tag, fallback }) => {
                    const id = `variable-${tag}`;
                    const error = errors[`variables.${tag}`];
                    const required = fallback === null;

                    return (
                        <div
                            key={tag}
                            className="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] sm:items-start sm:gap-6 sm:px-6"
                        >
                            <div className="flex min-w-0 items-start justify-between gap-3 sm:pt-1.5">
                                <div className="grid min-w-0 gap-1">
                                    <label
                                        htmlFor={id}
                                        className="truncate text-sm font-medium"
                                    >
                                        {labelFor(tag)}
                                    </label>
                                    <code className="w-fit max-w-full truncate rounded-md border bg-muted/40 px-1.5 py-0.5 font-mono text-xs text-muted-foreground">
                                        {`{{ ${tag} }}`}
                                    </code>
                                </div>
                                {required && isEmpty(tag) && (
                                    <StatusBadge tone="warning">
                                        Required
                                    </StatusBadge>
                                )}
                            </div>

                            <div className="grid min-w-0 gap-1.5">
                                <Input
                                    id={id}
                                    value={values[tag] ?? ''}
                                    placeholder={fallback ?? 'Value'}
                                    aria-invalid={error ? true : undefined}
                                    aria-describedby={
                                        required ? undefined : `${id}-hint`
                                    }
                                    onChange={(event) =>
                                        onChange({
                                            ...values,
                                            [tag]: event.target.value,
                                        })
                                    }
                                />
                                {!required && (
                                    <p
                                        id={`${id}-hint`}
                                        className="text-xs text-muted-foreground"
                                    >
                                        {`Optional. Left empty, it shows "${fallback}".`}
                                    </p>
                                )}
                                <InputError message={error} />
                            </div>
                        </div>
                    );
                })}
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
