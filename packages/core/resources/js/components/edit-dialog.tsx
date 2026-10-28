import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Edits one or two text fields of a record in a dialog, submitting with PUT.
 */
export function EditDialog({
    action,
    title,
    fields,
    trigger = 'Edit',
}: {
    action: { action: string; method: 'post' | 'get' };
    title: string;
    fields: { name: string; label: string; value: string | null }[];
    trigger?: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    {trigger}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {fields.map((field) => (
                                <div key={field.name} className="grid gap-2">
                                    <Label htmlFor={`edit-${field.name}`}>
                                        {field.label}
                                    </Label>
                                    <Input
                                        id={`edit-${field.name}`}
                                        name={field.name}
                                        defaultValue={field.value ?? ''}
                                    />
                                    <InputError message={errors[field.name]} />
                                </div>
                            ))}
                            <DialogFooter>
                                <Button disabled={processing}>Save</Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
