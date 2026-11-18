import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@nudge/components/confirm-dialog';
import { Button } from '@/components/ui/button';

/**
 * A delete button that asks for confirmation first.
 */
export function ConfirmDelete({
    url,
    title,
    description,
    trigger = 'Delete',
}: {
    url: string;
    title: string;
    description: ReactNode;
    trigger?: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button variant="ghost" size="sm" onClick={() => setOpen(true)}>
                {trigger}
            </Button>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title={title}
                description={description}
                confirmLabel="Delete"
                onConfirm={() => {
                    setOpen(false);
                    router.delete(url, { preserveScroll: true });
                }}
            />
        </>
    );
}
