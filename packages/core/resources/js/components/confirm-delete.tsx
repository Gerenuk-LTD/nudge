import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Trash2 } from 'lucide-react';
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
    size = 'sm',
}: {
    url: string;
    title: string;
    description: ReactNode;
    trigger?: ReactNode;
    /** Full size in a page header beside other actions, where it also gets a bin icon. Red either way, so it never passes for an ordinary action; small in a card or row. */
    size?: 'sm' | 'default';
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button
                variant="destructive-ghost"
                size={size}
                onClick={() => setOpen(true)}
            >
                {size === 'default' && <Trash2 />}
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
