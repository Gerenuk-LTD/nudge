import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="flex aspect-square size-8 shrink-0 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-5 text-white dark:text-black" />
            </div>
            <div className="ml-1.5 grid flex-1 text-left">
                <span className="truncate text-xl leading-tight font-bold tracking-tight">
                    {name}
                </span>
            </div>
        </>
    );
}
