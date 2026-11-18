import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

/**
 * tailwind-merge only knows Tailwind's default type scale, so it would read the custom `text-13` size as a colour and
 * drop it beside a real colour such as `text-red-600`. Registering it keeps both.
 */
const twMerge = extendTailwindMerge({
    extend: { theme: { text: ['13'] } },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}
