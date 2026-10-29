import { useLayoutEffect, useRef } from 'react';
import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const FIELDS = 'input:not([type=hidden]), select, textarea, [role=combobox]';

export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    const ref = useRef<HTMLParagraphElement>(null);

    // Mark the fields beside the message as invalid, so they take the input's error styling and screen readers announce it.
    useLayoutEffect(() => {
        const fields = Array.from(
            ref.current?.parentElement?.querySelectorAll<HTMLElement>(FIELDS) ??
                [],
        );

        fields.forEach((field) => field.setAttribute('aria-invalid', 'true'));

        return () =>
            fields.forEach((field) => field.removeAttribute('aria-invalid'));
    }, [message]);

    return message ? (
        <p
            {...props}
            ref={ref}
            className={cn('text-13 text-red-600 dark:text-red-400', className)}
        >
            {message}
        </p>
    ) : null;
}
