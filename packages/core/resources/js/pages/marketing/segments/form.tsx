import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import SegmentController from '@/actions/Gerenuk/Marketing/Http/Controllers/SegmentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    index as contactsIndex,
    show as showContact,
} from '@/routes/marketing/contacts';
import { index as segmentsIndex } from '@/routes/marketing/segments';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { SegmentGroupEditor } from '@marketing/components/segment-builder';
import { postJson } from '@marketing/lib/http';
import type { SegmentField, SegmentGroup } from '@marketing/types';

type Preview = {
    count: number;
    sample: { id: number; email: string; name: string | null }[];
};

export default function SegmentForm({
    segment,
    fields,
}: {
    segment: { id: number; name: string; definition: SegmentGroup } | null;
    fields: SegmentField[];
}) {
    // The definition is kept outside useForm, whose types cannot describe a recursive structure.
    const [definition, setDefinition] = useState<SegmentGroup>(
        segment?.definition ?? { match: 'all', conditions: [] },
    );
    const form = useForm({ name: segment?.name ?? '' });
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewErrors, setPreviewErrors] = useState<
        Record<string, string[]>
    >({});
    const [previewing, setPreviewing] = useState(false);

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

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({ ...data, definition }));
        form.submit(
            segment
                ? SegmentController.update(segment.id)
                : SegmentController.store(),
            { preserveScroll: true },
        );
    };

    const errors = form.errors as Record<string, string | undefined>;
    const errorPaths = [...Object.keys(previewErrors), ...Object.keys(errors)];
    const definitionErrors = [
        ...Object.values(previewErrors).flat(),
        ...Object.entries(errors)
            .filter(([key]) => key.startsWith('definition'))
            .map(([, message]) => message),
    ];

    return (
        <>
            <Head title={segment ? segment.name : 'New segment'} />

            <PageBody>
                <PageHeader
                    title={segment ? segment.name : 'New segment'}
                    description="Segments update automatically: contacts join and leave as their details change."
                />

                <form
                    onSubmit={submit}
                    className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"
                >
                    <div className="space-y-6">
                        <div className="grid max-w-md gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                required
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            <InputError message={errors.name} />
                        </div>

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

                        <div className="flex gap-2">
                            <Button disabled={form.processing}>
                                {form.processing && <Spinner />}
                                Save segment
                            </Button>
                            <Button variant="ghost" asChild>
                                <Link href={segmentsIndex()}>Cancel</Link>
                            </Button>
                        </div>
                    </div>

                    <aside className="h-fit space-y-4 rounded-xl border p-4">
                        <div>
                            <p className="text-sm text-muted-foreground">
                                Matching contacts
                            </p>
                            <p className="flex items-center gap-2 text-3xl font-semibold tabular-nums">
                                {preview ? preview.count.toLocaleString() : '—'}
                                {previewing && <Spinner />}
                            </p>
                        </div>
                        {preview && preview.sample.length > 0 && (
                            <ul className="space-y-1 text-sm">
                                {preview.sample.map((contact) => (
                                    <li key={contact.id} className="truncate">
                                        <Link
                                            href={showContact(contact.id)}
                                            className="hover:underline"
                                        >
                                            {contact.email}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {segment && (
                            <Link
                                href={contactsIndex({
                                    query: { segment: segment.id },
                                })}
                                className="text-sm underline underline-offset-4"
                            >
                                View all saved matches
                            </Link>
                        )}
                    </aside>
                </form>
            </PageBody>
        </>
    );
}

SegmentForm.layout = {
    breadcrumbs: [{ title: 'Segments', href: segmentsIndex() }],
};
