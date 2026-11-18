import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import SegmentController from '@/actions/Gerenuk/Nudge/Http/Controllers/SegmentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    index as contactsIndex,
    show as showContact,
} from '@/routes/nudge/contacts';
import { index as segmentsIndex } from '@/routes/nudge/segments';
import { ContactAvatar } from '@nudge/components/contact-fields';
import { EditorBar } from '@nudge/components/editor-bar';
import { PageBody } from '@nudge/components/page-header';
import {
    SegmentGroupEditor,
    segmentStarters,
} from '@nudge/components/segment-builder';
import {
    useSaveShortcut,
    useUnsavedChangesGuard,
} from '@nudge/hooks/use-editor';
import { postJson } from '@nudge/lib/http';
import type { SegmentField, SegmentGroup } from '@nudge/types';

type Preview = {
    count: number;
    total: number;
    sample: { id: number; email: string; name: string | null }[];
};

export default function SegmentForm({
    segment,
    fields,
}: {
    segment: { id: number; name: string; definition: SegmentGroup } | null;
    fields: SegmentField[];
}) {
    const { url } = usePage();
    // A new segment can start from one of the suggestions on the empty segments page.
    const [starter] = useState(() =>
        segment
            ? undefined
            : segmentStarters(fields).find(
                  (candidate) =>
                      candidate.key ===
                      new URLSearchParams(url.split('?')[1]).get('starter'),
              ),
    );

    // The definition is kept outside useForm, whose types cannot describe a recursive structure.
    const [definition, setDefinition] = useState<SegmentGroup>(
        segment?.definition ??
            starter?.definition ?? { match: 'all', conditions: [] },
    );
    const form = useForm({ name: segment?.name ?? starter?.name ?? '' });
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewErrors, setPreviewErrors] = useState<
        Record<string, string[]>
    >({});
    const [previewing, setPreviewing] = useState(false);
    // A condition just added has no value yet, so the preview's complaints wait until saving is tried.
    const [triedSaving, setTriedSaving] = useState(false);
    const unfinished = Object.keys(previewErrors).length > 0;

    // Refresh the live count shortly after the definition stops changing.
    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setPreviewing(true);

            try {
                const result = await postJson<Preview>(
                    SegmentController.preview.url(),
                    { definition },
                    controller.signal,
                );

                if (result.ok) {
                    setPreview(result.data);
                    setPreviewErrors({});
                } else {
                    setPreviewErrors(result.errors);
                }
            } catch {
                // Aborted by a newer change.
            } finally {
                setPreviewing(false);
            }
        }, 400);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [definition]);

    const [savedDefinition, setSavedDefinition] = useState(() =>
        JSON.stringify(definition),
    );
    const dirty =
        form.isDirty || JSON.stringify(definition) !== savedDefinition;

    const save = () => {
        setTriedSaving(true);

        if (segment && !dirty) {
            return;
        }

        const sent = structuredClone(form.data);

        form.transform((data) => ({ ...data, definition }));
        form.submit(
            segment
                ? SegmentController.update(segment.id)
                : SegmentController.store(),
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.setDefaults(sent);
                    setSavedDefinition(JSON.stringify(definition));
                },
            },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        save();
    };

    useSaveShortcut(save);
    useUnsavedChangesGuard(dirty && !form.processing);

    const errors = form.errors as Record<string, string | undefined>;
    const shownPreviewErrors = triedSaving ? previewErrors : {};
    const errorPaths = [
        ...Object.keys(shownPreviewErrors),
        ...Object.keys(errors),
    ];
    const definitionErrors = [
        ...Object.values(shownPreviewErrors).flat(),
        ...Object.entries(errors)
            .filter(([key]) => key.startsWith('definition'))
            .map(([, message]) => message),
    ];

    return (
        <>
            <Head title={segment ? segment.name : 'New segment'} />

            <PageBody>
                <EditorBar
                    back={{ href: segmentsIndex(), label: 'segments' }}
                    title={form.data.name}
                    titlePlaceholder="Name this segment"
                    titleError={errors.name}
                    onTitleChange={(name) => form.setData('name', name)}
                    saveState={
                        form.processing
                            ? 'saving'
                            : form.hasErrors
                              ? 'error'
                              : dirty || !segment
                                ? 'unsaved'
                                : 'saved'
                    }
                    meta="Contacts join and leave as their details change"
                    actions={
                        <Button
                            onClick={save}
                            disabled={
                                form.processing || (segment !== null && !dirty)
                            }
                        >
                            {form.processing && <Spinner />}
                            {segment ? 'Save segment' : 'Create segment'}
                        </Button>
                    }
                />

                <form
                    onSubmit={submit}
                    className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"
                >
                    <div className="space-y-6">
                        <SegmentGroupEditor
                            group={definition}
                            fields={fields}
                            errorPaths={errorPaths}
                            onChange={setDefinition}
                        />

                        {definitionErrors.length > 0 && (
                            <ul className="space-y-1">
                                {[...new Set(definitionErrors)].map(
                                    (message) => (
                                        <InputError
                                            key={message}
                                            message={message}
                                        />
                                    ),
                                )}
                            </ul>
                        )}
                    </div>

                    <aside className="h-fit overflow-hidden rounded-xl border bg-card shadow-xs lg:sticky lg:top-[calc(var(--nudge-header-height,4rem)+5.5rem)]">
                        <div className="space-y-3 p-5">
                            <p className="text-sm text-muted-foreground">
                                Matching contacts
                            </p>
                            <div className="flex items-baseline gap-2">
                                {preview ? (
                                    <span className="text-3xl font-semibold tabular-nums">
                                        {preview.count.toLocaleString()}
                                    </span>
                                ) : (
                                    <Skeleton className="h-9 w-16" />
                                )}
                                {preview && (
                                    <span className="text-sm text-muted-foreground">
                                        of {preview.total.toLocaleString()} ·{' '}
                                        {share(preview)}%
                                    </span>
                                )}
                                {previewing && (
                                    <Spinner className="ml-auto text-muted-foreground" />
                                )}
                            </div>
                            {unfinished && (
                                <p className="text-xs text-muted-foreground">
                                    Fill in every condition to update the count.
                                </p>
                            )}
                            <div
                                className="h-1.5 overflow-hidden rounded-full bg-muted"
                                role="presentation"
                            >
                                <div
                                    className="h-full rounded-full bg-foreground/70 transition-[width] duration-300"
                                    style={{
                                        width: `${preview ? share(preview) : 0}%`,
                                    }}
                                />
                            </div>
                        </div>

                        {preview && preview.count === 0 && (
                            <div className="flex flex-col items-center gap-2 border-t bg-muted/40 px-5 py-8 text-center">
                                <span className="flex size-9 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
                                    <Users className="size-4" />
                                </span>
                                <p className="text-sm font-medium">
                                    No one matches yet
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Loosen a condition, or switch to matching
                                    any of them.
                                </p>
                            </div>
                        )}

                        {preview && preview.sample.length > 0 && (
                            <div className="border-t">
                                <p className="px-5 pt-3 text-xs font-medium text-muted-foreground">
                                    Newest matches
                                </p>
                                <ul className="p-2">
                                    {preview.sample.map((contact) => (
                                        <li key={contact.id}>
                                            <Link
                                                href={showContact(contact.id)}
                                                className="flex items-center gap-2.5 rounded-md px-3 py-1.5 hover:bg-muted/60"
                                            >
                                                <ContactAvatar
                                                    name={contact.name}
                                                    email={contact.email}
                                                    className="size-6 text-[10px]"
                                                />
                                                <span className="min-w-0 flex-1 truncate text-sm">
                                                    {contact.name ??
                                                        contact.email}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {segment && (
                            <Link
                                href={contactsIndex({
                                    query: { segment: segment.id },
                                })}
                                className="flex items-center justify-between gap-2 border-t bg-muted/40 px-5 py-3 text-sm font-medium hover:bg-muted/70"
                            >
                                View all saved matches
                                <ArrowRight className="size-4 text-muted-foreground" />
                            </Link>
                        )}
                    </aside>
                </form>
            </PageBody>
        </>
    );
}

/** The share of active contacts that match, as a whole percentage. */
function share(preview: Preview): number {
    return preview.total > 0
        ? Math.round((preview.count / preview.total) * 100)
        : 0;
}

SegmentForm.layout = {
    breadcrumbs: [{ title: 'Segments', href: segmentsIndex() }],
};
