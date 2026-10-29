import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ArrowLeft, Check, CircleAlert, LoaderCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type SaveState = 'saved' | 'saving' | 'unsaved' | 'error';

const saveStates: Record<SaveState, { label: string; icon: ReactNode }> = {
    saved: {
        label: 'Saved',
        icon: (
            <Check className="size-3.5 text-emerald-600 dark:text-emerald-400" />
        ),
    },
    saving: {
        label: 'Saving…',
        icon: <LoaderCircle className="size-3.5 animate-spin" />,
    },
    unsaved: {
        label: 'Unsaved changes',
        icon: <span className="size-1.5 rounded-full bg-amber-500" />,
    },
    error: {
        label: 'Not saved, check the highlighted fields',
        icon: (
            <CircleAlert className="size-3.5 text-red-600 dark:text-red-400" />
        ),
    },
};

/**
 * The bar across the top of every builder page: where it came from, what is being edited and whether it is saved,
 * the stages when there are some, and the main actions. It stays in view while the page scrolls, so saving never
 * means hunting for a button.
 */
export function EditorBar({
    back,
    title,
    onTitleChange,
    titlePlaceholder = 'Untitled',
    titleError,
    saveState,
    meta,
    stepper,
    actions,
}: {
    back: { href: NonNullable<InertiaLinkProps['href']>; label: string };
    title: string;
    /** Makes the title editable in place, like a document name. */
    onTitleChange?: (title: string) => void;
    titlePlaceholder?: string;
    titleError?: string;
    saveState?: SaveState;
    meta?: ReactNode;
    stepper?: ReactNode;
    actions?: ReactNode;
}) {
    const state = saveState ? saveStates[saveState] : null;

    return (
        <div className="sticky top-[var(--marketing-header-height,4rem)] z-10 -mx-4 -mt-6 border-b bg-background/85 px-4 py-3 backdrop-blur md:-mx-8 md:-mt-8 md:px-8">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div className="flex min-w-0 flex-1 items-center gap-3">
                    <Link
                        href={back.href}
                        aria-label={`Back to ${back.label}`}
                        className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-card text-muted-foreground shadow-xs transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                    </Link>
                    <div className="min-w-0 flex-1">
                        {onTitleChange ? (
                            <h1 className="flex">
                                <input
                                    value={title}
                                    placeholder={titlePlaceholder}
                                    aria-label="Name"
                                    aria-invalid={titleError ? true : undefined}
                                    title={titleError}
                                    onChange={(event) =>
                                        onTitleChange(event.target.value)
                                    }
                                    className="-mx-1.5 w-full max-w-md truncate rounded-md bg-transparent px-1.5 text-base font-semibold outline-none placeholder:text-muted-foreground/70 hover:bg-muted/70 focus:bg-card focus:ring-2 focus:ring-ring/40 aria-invalid:ring-2 aria-invalid:ring-destructive/40"
                                />
                            </h1>
                        ) : (
                            <h1 className="truncate text-base font-semibold">
                                {title}
                            </h1>
                        )}
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            {state && (
                                <span
                                    role="status"
                                    className="flex items-center gap-1.5"
                                >
                                    {state.icon}
                                    {state.label}
                                </span>
                            )}
                            {state && meta && <span aria-hidden>·</span>}
                            {meta}
                        </div>
                    </div>
                </div>

                {stepper && (
                    <div className="order-last w-full lg:order-none lg:w-auto">
                        {stepper}
                    </div>
                )}

                {actions && (
                    <div className="flex shrink-0 items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
        </div>
    );
}

export type StepperStep = {
    key: string;
    label: string;
    /** `complete` shows a tick; `attention` flags something still to do there. */
    state?: 'complete' | 'attention';
};

/**
 * The stages of a builder as a row of numbered steps joined by lines. With `onSelect` the steps can be visited in
 * any order; without it the row only shows progress.
 */
export function Stepper({
    steps,
    current,
    onSelect,
}: {
    steps: StepperStep[];
    current: string;
    onSelect?: (key: string) => void;
}) {
    return (
        <ol className="flex items-center gap-2" aria-label="Steps">
            {steps.map((step, index) => {
                const active = step.key === current;
                const marker = (
                    <>
                        <span
                            className={cn(
                                'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium tabular-nums transition-colors',
                                active &&
                                    'border-foreground bg-foreground text-background',
                                !active &&
                                    step.state === 'complete' &&
                                    'border-emerald-600/30 bg-emerald-50 text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-400',
                                !active &&
                                    step.state === 'attention' &&
                                    'border-amber-500/40 bg-amber-50 text-amber-800 dark:bg-amber-400/10 dark:text-amber-400',
                                !active &&
                                    !step.state &&
                                    'bg-card text-muted-foreground',
                            )}
                        >
                            {!active && step.state === 'complete' ? (
                                <Check className="size-3.5" />
                            ) : (
                                index + 1
                            )}
                        </span>
                        <span
                            className={cn(
                                'text-sm whitespace-nowrap',
                                active
                                    ? 'font-medium text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {step.label}
                        </span>
                    </>
                );

                return (
                    <li key={step.key} className="flex items-center gap-2">
                        {index > 0 && (
                            <span
                                aria-hidden
                                className="h-px w-4 bg-border sm:w-8"
                            />
                        )}
                        {onSelect ? (
                            <button
                                type="button"
                                aria-current={active ? 'step' : undefined}
                                onClick={() => onSelect(step.key)}
                                className="flex items-center gap-2 rounded-md px-1.5 py-1 outline-none hover:bg-muted/60 focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                {marker}
                            </button>
                        ) : (
                            <span
                                aria-current={active ? 'step' : undefined}
                                className="flex items-center gap-2 px-1.5 py-1"
                            >
                                {marker}
                            </span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
