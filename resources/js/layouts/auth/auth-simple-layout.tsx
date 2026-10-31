import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * Sign-in, sign-up and onboarding: the product mark above a single bordered card on the app's grey page.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-10">
            <div className="flex w-full max-w-sm flex-col gap-6">
                <Link
                    href={home()}
                    className="flex items-center justify-center gap-3 self-center rounded-md text-3xl font-bold tracking-tight"
                >
                    <span className="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-xs">
                        <AppLogoIcon className="size-6" />
                    </span>
                    {name}
                </Link>

                <div className="rounded-xl border bg-card p-6 shadow-xs sm:p-8">
                    {(title || description) && (
                        <div className="mb-6 space-y-1.5">
                            {title && (
                                <h1 className="text-xl font-semibold">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="text-sm text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}
                    {children}
                </div>
            </div>
        </div>
    );
}
