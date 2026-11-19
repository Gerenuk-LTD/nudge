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
 * everyone gets, as a compact two-column form. Only optional ones are marked: the header counts what's left,
 * and the send checklist blocks sending until the rest are filled.
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
    const missing = variables.filter(
        ({ tag, fallback }) => fallback === null && !(values[tag] ?? '').trim(),
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

            <div className="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2 sm:px-6">
                {variables.map(({ tag, fallback }) => {
                    const id = `variable-${tag}`;
                    const error = errors[`variables.${tag}`];

                    return (
                        <div
                            key={tag}
                            className="grid min-w-0 content-start gap-2"
                        >
                            <div className="flex min-w-0 items-baseline justify-between gap-3">
                                <label
                                    htmlFor={id}
                                    className="truncate text-sm font-medium"
                                >
                                    {labelFor(tag)}
                                    {fallback !== null && (
                                        <span className="ml-1.5 font-normal text-muted-foreground">
                                            Optional
                                        </span>
                                    )}
                                </label>
                                <code
                                    className="max-w-1/2 truncate font-mono text-xs text-muted-foreground"
                                    title="The tag this fills in the email"
                                >
                                    {`{{ ${tag} }}`}
                                </code>
                            </div>
                            <Input
                                id={id}
                                value={values[tag] ?? ''}
                                placeholder={
                                    fallback !== null
                                        ? `${fallback} if left empty`
                                        : undefined
                                }
                                aria-invalid={error ? true : undefined}
                                onChange={(event) =>
                                    onChange({
                                        ...values,
                                        [tag]: event.target.value,
                                    })
                                }
                            />
                            <InputError message={error} />
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
