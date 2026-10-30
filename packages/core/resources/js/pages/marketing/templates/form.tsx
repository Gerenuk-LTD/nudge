import { Head, router, useForm } from '@inertiajs/react';
import { Copy, Ellipsis, Trash2 } from 'lucide-react';
import { useState } from 'react';
import EmailTemplateController from '@/actions/Gerenuk/Marketing/Http/Controllers/EmailTemplateController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { index as templatesIndex } from '@/routes/marketing/templates';
import { EmailContentEditor } from '@marketing/components/email-editors';
import type { MergeTag } from '@marketing/components/email-editors';
import type { SaveState } from '@marketing/components/editor-bar';
import { EditorBar } from '@marketing/components/editor-bar';
import { PageBody } from '@marketing/components/page-header';
import { EmailEnvelope } from '@marketing/components/email-envelope';
import {
    useSaveShortcut,
    useUnsavedChangesGuard,
} from '@marketing/hooks/use-editor';

type Template = {
    id: number;
    name: string;
    description: string | null;
    subject: string | null;
    preheader: string | null;
};

export default function TemplateForm({
    template,
    editor,
    editorState,
    html,
    mergeTags,
}: {
    template: Template | null;
    editor: string;
    editorState: Record<string, unknown> | null;
    html: string;
    mergeTags: MergeTag[];
}) {
    const form = useForm({
        name: template?.name ?? '',
        description: template?.description ?? '',
        subject: template?.subject ?? '',
        preheader: template?.preheader ?? '',
        editor,
        // Kept as JSON text: the form's types cannot describe a free-form document, and text still tracks changes.
        editor_state: editorState === null ? '' : JSON.stringify(editorState),
        html,
    });
    form.transform((data) => ({
        ...data,
        editor_state:
            data.editor_state === '' ? null : JSON.parse(data.editor_state),
    }));

    const save = () => {
        if (template && !form.isDirty) {
            return;
        }

        const sent = structuredClone(form.data);

        form.submit(
            template
                ? EmailTemplateController.update(template.id)
                : EmailTemplateController.store(),
            {
                preserveScroll: true,
                onSuccess: () => form.setDefaults(sent),
            },
        );
    };

    useSaveShortcut(save);
    useUnsavedChangesGuard(form.isDirty && !form.processing);

    const saveState: SaveState = form.processing
        ? 'saving'
        : form.hasErrors
          ? 'error'
          : form.isDirty || !template
            ? 'unsaved'
            : 'saved';

    return (
        <>
            <Head title={template?.name ?? 'New template'} />

            <PageBody>
                <EditorBar
                    back={{ href: templatesIndex(), label: 'templates' }}
                    title={form.data.name}
                    titlePlaceholder="Name this template"
                    titleError={form.errors.name}
                    onTitleChange={(name) => form.setData('name', name)}
                    saveState={saveState}
                    meta={
                        <input
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            placeholder="Add a description"
                            aria-label="Description"
                            className="-mx-1 w-56 min-w-0 truncate rounded bg-transparent px-1 outline-none placeholder:text-muted-foreground/70 hover:bg-muted/70 focus:bg-card focus:text-foreground focus:ring-2 focus:ring-ring/40 sm:w-80"
                        />
                    }
                    actions={
                        <>
                            {template && <TemplateMenu template={template} />}
                            <Button
                                onClick={save}
                                disabled={
                                    form.processing ||
                                    (template !== null && !form.isDirty)
                                }
                            >
                                {form.processing && <Spinner />}
                                {template ? 'Save' : 'Create template'}
                            </Button>
                        </>
                    }
                />

                {form.errors.name && (
                    <p className="-mt-3 text-13 text-red-600 dark:text-red-400">
                        {form.errors.name}
                    </p>
                )}

                <EmailEnvelope
                    rows={[
                        {
                            name: 'subject',
                            label: 'Subject',
                            value: form.data.subject,
                            onChange: (subject) =>
                                form.setData('subject', subject),
                            placeholder:
                                'Optional. Emails made from this template start with it',
                            error: form.errors.subject,
                            mergeTags,
                        },
                        {
                            name: 'preheader',
                            label: 'Preview text',
                            value: form.data.preheader,
                            onChange: (preheader) =>
                                form.setData('preheader', preheader),
                            placeholder:
                                'The line inboxes show after the subject',
                            error: form.errors.preheader,
                            muted: true,
                        },
                    ]}
                />

                <div className="space-y-2">
                    <EmailContentEditor
                        content={{
                            editor: form.data.editor,
                            state:
                                form.data.editor_state === ''
                                    ? null
                                    : JSON.parse(form.data.editor_state),
                            html: form.data.html,
                        }}
                        mergeTags={mergeTags}
                        onChange={(content) =>
                            form.setData((data) => ({
                                ...data,
                                editor: content.editor,
                                editor_state:
                                    content.state === null
                                        ? ''
                                        : JSON.stringify(content.state),
                                html: content.html,
                            }))
                        }
                    />
                    <InputError message={form.errors.html} />
                </div>
            </PageBody>
        </>
    );
}

/**
 * Less frequent actions on a saved template, kept out of the way of saving.
 */
function TemplateMenu({ template }: { template: Template }) {
    const [deleting, setDeleting] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="More actions"
                    >
                        <Ellipsis />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        onSelect={() =>
                            router.post(
                                EmailTemplateController.duplicate.url(
                                    template.id,
                                ),
                            )
                        }
                    >
                        <Copy />
                        Duplicate
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => setDeleting(true)}
                    >
                        <Trash2 />
                        Delete template
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent>
                    <DialogTitle>Delete {template.name}?</DialogTitle>
                    <DialogDescription>
                        Emails already made from it keep their content.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(
                                    EmailTemplateController.destroy.url(
                                        template.id,
                                    ),
                                )
                            }
                        >
                            Delete template
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

TemplateForm.layout = {
    breadcrumbs: [{ title: 'Templates', href: templatesIndex() }],
};
