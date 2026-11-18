import { router, useForm, usePage } from '@inertiajs/react';
import { BookmarkPlus, Check, LayoutTemplate } from 'lucide-react';
import EmailTemplateController from '@/actions/Gerenuk/Nudge/Http/Controllers/EmailTemplateController';
import InputError from '@/components/input-error';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { asDocument } from '@nudge/components/email-editors/blocks/model';
import { renderEmail } from '@nudge/components/email-editors/blocks/render';
import type { TemplateOption } from '@nudge/types';

/**
 * The HTML to show for a template. Builder starters carry only their document, so they are rendered here.
 */
export function templateHtml(template: {
    editor: string;
    editor_state: unknown;
    html: string | null;
}): string {
    const document =
        template.editor === 'blocks' ? asDocument(template.editor_state) : null;

    return document ? renderEmail(document) : (template.html ?? '');
}

/** The width emails are designed at; thumbnails render at this width and scale down. */
const EMAIL_WIDTH = 600;

/**
 * A scaled-down, non-interactive render of an email for cards and pickers. The HTML runs sandboxed, without scripts.
 */
export function EmailThumbnail({
    html,
    scale = 0.4,
    height = 200,
    className,
}: {
    html: string;
    scale?: number;
    height?: number;
    className?: string;
}) {
    return (
        <div
            aria-hidden
            className={cn(
                'pointer-events-none relative overflow-hidden bg-white',
                className,
            )}
            style={{ height }}
        >
            <iframe
                title="Email thumbnail"
                sandbox=""
                srcDoc={html}
                tabIndex={-1}
                loading="lazy"
                className="absolute top-0 left-1/2 origin-top border-0"
                style={{
                    width: EMAIL_WIDTH,
                    height: height / scale,
                    transform: `translateX(-50%) scale(${scale})`,
                }}
            />
        </div>
    );
}

/**
 * The page's `templates` prop, fetched afresh each time `active` turns true so templates saved since the page loaded
 * (or since the browser restored it from history) are offered too. It carries every template's HTML, so it is never
 * part of the first page load. `failed` is set when the fetch did not succeed, with `retry` to try again.
 */
export function useTemplateOptions(active: boolean): {
    templates: TemplateOption[] | undefined;
    loading: boolean;
    failed: boolean;
    retry: () => void;
} {
    const templates = usePage<{ templates?: TemplateOption[] }>().props
        .templates;
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    const load = () => {
        setLoading(true);
        setFailed(false);
        router.reload({
            only: ['templates'],
            onSuccess: () => setFailed(false),
            onHttpException: () => setFailed(true),
            onNetworkError: () => setFailed(true),
            onFinish: () => setLoading(false),
        });
    };

    useEffect(() => {
        if (active) {
            load();
        }
        // Only opening should refetch; `load` is recreated every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active]);

    return { templates, loading, failed, retry: load };
}

/**
 * A grid of templates to choose one from, the workspace's own first, then the built-in starters.
 */
export function TemplatePicker({
    options: { templates, loading, failed, retry },
    value,
    onChange,
}: {
    options: ReturnType<typeof useTemplateOptions>;
    value: string | null;
    onChange: (template: TemplateOption) => void;
}) {
    if (failed) {
        return (
            <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed bg-muted/40 px-6 py-8 text-center">
                <p className="text-sm text-muted-foreground">
                    Templates could not be loaded.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={retry}
                >
                    Try again
                </Button>
            </div>
        );
    }

    // Show the last list while a fresh one loads, so reopening the picker does not flash skeletons.
    if (templates === undefined || (loading && templates.length === 0)) {
        return (
            <div
                className="grid grid-cols-2 gap-3 sm:grid-cols-3"
                aria-busy="true"
            >
                {Array.from({ length: 3 }, (_, index) => (
                    <Skeleton key={index} className="h-44 rounded-lg" />
                ))}
            </div>
        );
    }

    const groups = [
        { title: 'Your templates', items: templates.filter((t) => t.saved) },
        { title: 'Starters', items: templates.filter((t) => !t.saved) },
    ].filter((group) => group.items.length > 0);

    return (
        <div className="space-y-4">
            {groups.map((group) => (
                <div key={group.title} className="space-y-2">
                    <p className="text-13 font-medium text-muted-foreground">
                        {group.title}
                    </p>
                    <div
                        role="radiogroup"
                        aria-label={group.title}
                        className="grid grid-cols-2 gap-3 sm:grid-cols-3"
                    >
                        {group.items.map((template) => {
                            const selected = template.key === value;

                            return (
                                <button
                                    key={template.key}
                                    type="button"
                                    role="radio"
                                    aria-checked={selected}
                                    onClick={() => onChange(template)}
                                    className={cn(
                                        'group relative overflow-hidden rounded-lg border bg-card text-left transition-[border-color,box-shadow] outline-none hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                        selected &&
                                            'border-foreground ring-1 ring-foreground hover:border-foreground',
                                    )}
                                >
                                    <EmailThumbnail
                                        html={templateHtml(template)}
                                        scale={0.3}
                                        height={120}
                                        className="border-b"
                                    />
                                    <span className="block px-3 py-2">
                                        <span className="block truncate text-13 font-medium">
                                            {template.name}
                                        </span>
                                        {template.description && (
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {template.description}
                                            </span>
                                        )}
                                    </span>
                                    {selected && (
                                        <span className="absolute top-2 right-2 flex size-5 items-center justify-center rounded-full bg-foreground text-background">
                                            <Check className="size-3" />
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * A button that opens the template picker and hands back the chosen template, for replacing an email's content.
 */
export function TemplateDialog({
    onChoose,
}: {
    onChoose: (template: TemplateOption) => void;
}) {
    const [open, setOpen] = useState(false);
    const [chosen, setChosen] = useState<TemplateOption | null>(null);
    const options = useTemplateOptions(open);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setChosen(null);
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <LayoutTemplate />
                    Start from a template
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogTitle>Start from a template</DialogTitle>
                <DialogDescription>
                    This replaces the email's content, and its subject and
                    preview text when the template has them.
                </DialogDescription>
                <TemplatePicker
                    options={options}
                    value={chosen?.key ?? null}
                    onChange={setChosen}
                />
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        disabled={chosen === null}
                        onClick={() => {
                            if (chosen) {
                                onChoose(chosen);
                                setOpen(false);
                            }
                        }}
                    >
                        Use template
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A button that saves an email as a new template under a name. Pass `content` to save what is on screen, or
 * `campaignId` to save a campaign as the server has it, which for a sent campaign is what went out.
 */
export function SaveTemplateDialog({
    defaultName,
    content,
    campaignId,
}: {
    defaultName: string;
    content?: {
        subject: string;
        preheader: string;
        editor: string;
        editor_state: unknown;
        html: string;
    };
    campaignId?: number;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: defaultName });

    const save = (event: FormEvent) => {
        event.preventDefault();

        if (content) {
            form.transform((data) => ({ ...content, ...data }));
            form.submit(EmailTemplateController.store());
        } else if (campaignId !== undefined) {
            form.submit(EmailTemplateController.storeFromCampaign(campaignId));
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <BookmarkPlus />
                    Save as template
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Save as template</DialogTitle>
                <DialogDescription>
                    Keeps a copy of this email's subject and content to start
                    new emails from.
                </DialogDescription>
                <form onSubmit={save} className="space-y-4">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="template-name">Template name</Label>
                        <Input
                            id="template-name"
                            required
                            autoFocus
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <DialogFooter>
                        <Button disabled={form.processing}>
                            Save template
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
