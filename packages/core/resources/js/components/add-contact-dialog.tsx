import { Link, useForm } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import ContactController from '@/actions/Gerenuk/Nudge/Http/Controllers/ContactController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { create as createImport } from '@/routes/nudge/imports';
import {
    CustomFieldInput,
    ListPicker,
    SubscriptionSwitch,
} from '@nudge/components/contact-fields';
import type { CustomFieldValue } from '@nudge/components/contact-fields';
import { TagInput } from '@nudge/components/tag-input';
import { useCan } from '@nudge/hooks/use-workspace';
import type { ContactStatus, CustomField, Option } from '@nudge/types';

type FormData = {
    email: string;
    first_name: string;
    last_name: string;
    status: ContactStatus;
    custom_fields: Record<string, CustomFieldValue>;
    tags: string[];
    lists: number[];
};

/**
 * Adds one contact without leaving the list. "Add another" keeps the dialog open with the same lists and tags, for
 * entering a few people in a row; otherwise the new contact's profile opens.
 */
export function AddContactDialog({
    open,
    onOpenChange,
    lists,
    tags,
    customFields,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lists: Option[];
    tags: string[];
    customFields: CustomField[];
}) {
    const can = useCan();
    const email = useRef<HTMLInputElement>(null);
    const [addAnother, setAddAnother] = useState(false);
    const [showFields, setShowFields] = useState(false);

    const form = useForm<FormData>({
        email: '',
        first_name: '',
        last_name: '',
        status: 'subscribed',
        custom_fields: {},
        tags: [],
        lists: [],
    });

    const errors = form.errors as Record<string, string | undefined>;
    const fieldErrors = customFields.some(
        (field) => errors[`custom_fields.${field.key}`],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({ ...data, add_another: addAnother }));
        form.submit(ContactController.store(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (addAnother) {
                    form.reset(
                        'email',
                        'first_name',
                        'last_name',
                        'custom_fields',
                    );
                    email.current?.focus();
                }
            },
        });
    };

    const close = (next: boolean) => {
        onOpenChange(next);

        if (!next) {
            form.reset();
            form.clearErrors();
            setShowFields(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            {/* Pinned near the top, not centred, so errors or opening custom fields grow it downwards instead of moving it. */}
            <DialogContent className="top-4 max-h-[calc(100svh-2rem)] translate-y-0 grid-rows-[auto_minmax(0,1fr)_auto] gap-0 p-0 sm:top-[8svh] sm:max-h-[84svh] sm:max-w-xl">
                <div className="space-y-1.5 border-b px-6 pt-5 pb-4">
                    <DialogTitle className="text-base">Add contact</DialogTitle>
                    <DialogDescription className="text-13">
                        Add one person by hand.
                        {can('contacts.import') && (
                            <>
                                {' '}
                                Adding lots?{' '}
                                <Link
                                    href={createImport()}
                                    className="font-medium text-foreground underline-offset-4 hover:underline"
                                >
                                    Import a CSV
                                </Link>
                                .
                            </>
                        )}
                    </DialogDescription>
                </div>

                <form
                    id="add-contact"
                    onSubmit={submit}
                    className="space-y-5 overflow-y-auto px-6 py-5"
                >
                    <div className="grid content-start gap-2">
                        <Label htmlFor="new-email">Email address</Label>
                        <Input
                            ref={email}
                            id="new-email"
                            type="email"
                            required
                            autoFocus
                            autoComplete="off"
                            placeholder="name@example.com"
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="new-first-name">First name</Label>
                            <Input
                                id="new-first-name"
                                autoComplete="off"
                                value={form.data.first_name}
                                onChange={(event) =>
                                    form.setData(
                                        'first_name',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={errors.first_name} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="new-last-name">Last name</Label>
                            <Input
                                id="new-last-name"
                                autoComplete="off"
                                value={form.data.last_name}
                                onChange={(event) =>
                                    form.setData(
                                        'last_name',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={errors.last_name} />
                        </div>
                    </div>

                    {lists.length > 0 && (
                        <div className="grid content-start gap-2">
                            <Label>Lists</Label>
                            <ListPicker
                                lists={lists}
                                value={form.data.lists}
                                onChange={(value) =>
                                    form.setData('lists', value)
                                }
                            />
                            <InputError message={errors.lists} />
                        </div>
                    )}

                    <div className="grid content-start gap-2">
                        <Label htmlFor="new-tags">Tags</Label>
                        <TagInput
                            id="new-tags"
                            value={form.data.tags}
                            onChange={(value) => form.setData('tags', value)}
                            suggestions={tags}
                        />
                        <InputError message={errors.tags} />
                    </div>

                    {customFields.length > 0 && (
                        <div className="rounded-lg border">
                            <button
                                type="button"
                                aria-expanded={showFields || fieldErrors}
                                onClick={() => setShowFields(!showFields)}
                                className="flex w-full items-center gap-2 px-3.5 py-2.5 text-left text-13 font-medium"
                            >
                                <ChevronRight
                                    className={cn(
                                        'size-4 shrink-0 text-muted-foreground transition-transform',
                                        (showFields || fieldErrors) &&
                                            'rotate-90',
                                    )}
                                />
                                <span className="shrink-0">Custom fields</span>
                                <span className="min-w-0 truncate font-normal text-muted-foreground">
                                    {customFields
                                        .map((field) => field.label)
                                        .join(', ')}
                                </span>
                            </button>
                            {(showFields || fieldErrors) && (
                                <div className="grid gap-4 border-t px-3.5 py-4 sm:grid-cols-2">
                                    {customFields.map((field) => (
                                        <CustomFieldInput
                                            key={field.key}
                                            idPrefix="new-custom"
                                            field={field}
                                            value={
                                                form.data.custom_fields[
                                                    field.key
                                                ] ?? null
                                            }
                                            onChange={(value) =>
                                                form.setData('custom_fields', {
                                                    ...form.data.custom_fields,
                                                    [field.key]: value,
                                                })
                                            }
                                            error={
                                                errors[
                                                    `custom_fields.${field.key}`
                                                ]
                                            }
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    <SubscriptionSwitch
                        id="new-subscribed"
                        subscribed={form.data.status === 'subscribed'}
                        onChange={(subscribed) =>
                            form.setData(
                                'status',
                                subscribed ? 'subscribed' : 'unsubscribed',
                            )
                        }
                    />
                </form>

                <div className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/40 px-6 py-3">
                    <label className="flex items-center gap-2 text-13 text-muted-foreground">
                        <Checkbox
                            checked={addAnother}
                            onCheckedChange={(checked) =>
                                setAddAnother(checked === true)
                            }
                        />
                        Add another after this
                    </label>
                    <div className="ml-auto flex gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => close(false)}
                        >
                            Cancel
                        </Button>
                        <Button form="add-contact" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Add contact
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
