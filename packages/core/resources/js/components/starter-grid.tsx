import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** "Or start from one of these" under an empty screen's main button, as a grid of starter cards. */
export function StarterGrid({
    heading = 'Or start from one of these',
    columns = 2,
    children,
}: {
    heading?: string;
    columns?: 2 | 3;
    children: ReactNode;
}) {
    return (
        <div className="mt-5 w-full max-w-3xl space-y-2.5">
            <p className="text-xs font-medium text-muted-foreground">
                {heading}
            </p>
            <div
                className={cn(
                    'grid gap-2 text-left sm:grid-cols-2',
                    columns === 3 && 'lg:grid-cols-3',
                )}
            >
                {children}
            </div>
        </div>
    );
}

/** One starter: a name, a short description and an arrow. Give `href` for a link, or `onClick` for an action. */
export function StarterCard({
    title,
    description,
    icon: Icon,
    href,
    onClick,
}: {
    title: ReactNode;
    description: ReactNode;
    icon?: LucideIcon;
    href?: ComponentProps<typeof Link>['href'];
    onClick?: () => void;
}) {
    const className =
        'group flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 text-left shadow-xs transition-colors outline-none hover:border-foreground/20 focus-visible:ring-[3px] focus-visible:ring-ring/50';

    const body = (
        <>
            {Icon && <Icon className="size-4 shrink-0 text-muted-foreground" />}
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">
                    {title}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {description}
                </span>
            </span>
            <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
        </>
    );

    if (href !== undefined) {
        return (
            <Link href={href} className={className}>
                {body}
            </Link>
        );
    }

    return (
        <button type="button" onClick={onClick} className={className}>
            {body}
        </button>
    );
}
