import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    Check,
    ChevronDown,
    CircleAlert,
    CircleCheck,
    Filter,
    List as ListIcon,
    Monitor,
    Pencil,
    Send,
    Smartphone,
    TriangleAlert,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import CampaignController from '@/actions/Gerenuk/Marketing/Http/Controllers/CampaignController';
import InputError from '@/components/input-error';
import {
    SaveTemplateDialog,
    TemplateDialog,
    templateHtml,
} from '@marketing/components/template-picker';
import {
    EmailEnvelope,
    envelopeInput,
} from '@marketing/components/email-envelope';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index as campaignsIndex } from '@/routes/marketing/campaigns';
import { index as listsIndex } from '@/routes/marketing/lists';
import { index as providersIndex } from '@/routes/marketing/providers';
import { edit as sendingEdit } from '@/routes/marketing/sending';
import { create as createSegment } from '@/routes/marketing/segments';
import { EmailContentEditor } from '@marketing/components/email-editors';
import type { MergeTag } from '@marketing/components/email-editors';
import { NativeSelect } from '@/components/ui/native-select';
import type { SaveState } from '@marketing/components/editor-bar';
import { EditorBar, Stepper } from '@marketing/components/editor-bar';
import { PageBody } from '@marketing/components/page-header';
import { ToggleSwitch } from '@marketing/components/toggle-switch';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDateTime } from '@marketing/lib/format';
import type { ChecklistItem, Option } from '@marketing/types';

type Campaign = {
    id: number;
    name: string;
    subject: string | null;
    preheader: string | null;
    provider_id: number | null;
    from_name: string | null;
    from_email: string | null;
    reply_to: string | null;
    editor: string;
    editor_state: Record<string, unknown> | null;
    html: string | null;
    text: string | null;
    segment_id: number | null;
    track_opens: boolean;
    track_clicks: boolean;
    ab_enabled: boolean;
    ab_subject_b: string | null;
    ab_html_b: string | null;
    ab_editor_state_b: Record<string, unknown> | null;
    ab_sample_percent: number;
    ab_wait_minutes: number;
    ab_metric: 'clicks' | 'opens';
    status: string;
    audience_type: 'all' | 'lists' | 'segment';
    list_ids: number[];
    approved_at: string | null;
    scheduled_at: string | null;
};

type ProviderOption = {
    id: number;
    name: string;
    type: string;
    from_name: string | null;
    from_email: string | null;
    reply_to: string | null;
};

type Props = {
    campaign: Campaign;
    providers: ProviderOption[];
    lists: Option[];
    segments: Option[];
    mergeTags: MergeTag[];
    checklist?: ChecklistItem[];
    audienceCount?: number;
    audienceSizes?: {
        everyone: number;
        lists: Record<number, number>;
        segments: Record<number, number>;
    };
    requiresApproval: boolean;
};

type Draft = {
    name: string;
    subject: string;
    preheader: string;
    provider_id: number | null;
    from_name: string;
    from_email: string;
    reply_to: string;
    editor: string;
    /** The builder's document as JSON text, since the form's types cannot describe it. */
    editor_state: string;
    html: string;
    text: string;
    audience_type: Campaign['audience_type'];
    list_ids: number[];
    segment_id: number | null;
    track_opens: boolean;
    track_clicks: boolean;
    ab_enabled: boolean;
    ab_subject_b: string;
    ab_html_b: string;
    /** Version B's builder document as JSON text, like `editor_state`. */
    ab_editor_state_b: string;
    ab_sample_percent: number;
    ab_wait_minutes: number;
    ab_metric: 'clicks' | 'opens';
};

const steps = ['Content', 'Audience', 'Review and send'] as const;

/** The checklist items each step settles, so the stepper can tick a step off or flag what is left in it. */
const stepChecks: Record<(typeof steps)[number], string[]> = {
    Content: [
        'subject',
        'sender',
        'content',
        'unsubscribe',
        'postal_address',
        'provider',
        'ab_variant',
        'links',
    ],
    Audience: ['audience', 'ab_audience'],
    'Review and send': [],
};

function stepState(
    step: (typeof steps)[number],
    checklist: ChecklistItem[] | undefined,
): 'complete' | 'attention' | undefined {
    const items = (checklist ?? []).filter((item) =>
        stepChecks[step].includes(item.key),
    );

    if (items.length === 0) {
        return undefined;
    }

    return items.some((item) => item.blocking && !item.passed)
        ? 'attention'
        : 'complete';
}

export default function EditCampaign(props: Props) {
    const { campaign } = props;
    const [step, setStep] = useState<(typeof steps)[number]>('Content');
    const can = useCan();

    const form = useForm<Draft>({
        name: campaign.name,
        subject: campaign.subject ?? '',
        preheader: campaign.preheader ?? '',
        provider_id: campaign.provider_id,
        from_name: campaign.from_name ?? '',
        from_email: campaign.from_email ?? '',
        reply_to: campaign.reply_to ?? '',
        editor: campaign.editor,
        editor_state:
            campaign.editor_state === null
                ? ''
                : JSON.stringify(campaign.editor_state),
        html: campaign.html ?? '',
        text: campaign.text ?? '',
        audience_type: campaign.audience_type,
        list_ids: campaign.list_ids,
        segment_id: campaign.segment_id,
        track_opens: campaign.track_opens,
        track_clicks: campaign.track_clicks,
        ab_enabled: campaign.ab_enabled,
        ab_subject_b: campaign.ab_subject_b ?? '',
        ab_html_b: campaign.ab_html_b ?? '',
        ab_editor_state_b:
            campaign.ab_editor_state_b === null
                ? ''
                : JSON.stringify(campaign.ab_editor_state_b),
        ab_sample_percent: campaign.ab_sample_percent,
        ab_wait_minutes: campaign.ab_wait_minutes,
        ab_metric: campaign.ab_metric,
    });

    const scheduled = campaign.status === 'scheduled';
    const errors = form.errors as Record<string, string | undefined>;
    form.transform((data) => ({
        ...data,
        editor_state:
            data.editor_state === '' ? null : JSON.parse(data.editor_state),
        ab_editor_state_b:
            data.ab_editor_state_b === ''
                ? null
                : JSON.parse(data.ab_editor_state_b),
    }));
    useAutosave(form, campaign.id, scheduled);

    const saveState: SaveState = form.processing
        ? 'saving'
        : form.hasErrors
          ? 'error'
          : form.isDirty
            ? 'unsaved'
            : 'saved';
    const next = steps[steps.indexOf(step) + 1];
    const goTo = (name: (typeof steps)[number]) => {
        setStep(name);
        window.scrollTo({ top: 0 });
    };

    return (
        <>
            <Head title={campaign.name} />

            <PageBody>
                <EditorBar
                    back={{ href: campaignsIndex(), label: 'campaigns' }}
                    title={form.data.name}
                    titlePlaceholder="Name this campaign"
                    titleError={errors.name}
                    onTitleChange={
                        scheduled
                            ? undefined
                            : (name) => form.setData('name', name)
                    }
                    saveState={scheduled ? undefined : saveState}
                    meta={
                        scheduled
                            ? `Scheduled for ${formatDateTime(campaign.scheduled_at)}. Unschedule it to make changes.`
                            : 'Draft · saves as you type'
                    }
                    stepper={
                        <Stepper
                            steps={steps.map((name) => ({
                                key: name,
                                label: name,
                                state: stepState(name, props.checklist),
                            }))}
                            current={step}
                            onSelect={(name) =>
                                setStep(name as (typeof steps)[number])
                            }
                        />
                    }
                    actions={
                        scheduled ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        CampaignController.unschedule.url(
                                            campaign.id,
                                        ),
                                    )
                                }
                            >
                                Unschedule
                            </Button>
                        ) : (
                            next && (
                                <Button onClick={() => goTo(next)}>
                                    {next === 'Review and send'
                                        ? 'Review'
                                        : `Next: ${next}`}
                                    <ArrowRight />
                                </Button>
                            )
                        )
                    }
                />

                {step !== 'Review and send' && (
                    <fieldset disabled={scheduled} className="space-y-6">
                        {step === 'Content' && (
                            <ContentStep
                                form={form}
                                errors={errors}
                                props={props}
                            />
                        )}
                        {step === 'Audience' && (
                            <AudienceStep
                                form={form}
                                errors={errors}
                                props={props}
                            />
                        )}
                    </fieldset>
                )}

                {!scheduled && next && (
                    <div className="flex justify-end border-t pt-6">
                        <Button onClick={() => goTo(next)}>
                            {next === 'Review and send'
                                ? 'Review'
                                : `Next: ${next}`}
                            <ArrowRight />
                        </Button>
                    </div>
                )}

                {step === 'Review and send' && (
                    <ReviewStep
                        form={form}
                        props={props}
                        canSend={can('campaigns.send')}
                        canApprove={can('campaigns.approve')}
                        saving={form.isDirty || form.processing}
                        goTo={goTo}
                    />
                )}
            </PageBody>
        </>
    );
}

type DraftForm = ReturnType<typeof useForm<Draft>>;

/**
 * Save the draft a moment after the last change, then refresh the checks that depend on it.
 */
function useAutosave(form: DraftForm, campaignId: number, disabled: boolean) {
    const serialized = JSON.stringify(form.data);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        if (disabled || !form.isDirty) {
            return;
        }

        const timer = setTimeout(() => {
            form.submit(CampaignController.update(campaignId), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    form.setDefaults();
                    router.reload({ only: ['checklist', 'audienceCount'] });
                },
            });
        }, 1200);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [serialized, disabled]);
}

function ContentStep({
    form,
    errors,
    props,
}: {
    form: DraftForm;
    errors: Record<string, string | undefined>;
    props: Props;
}) {
    // Bumped when a template replaces the content, so the editor starts over from it.
    const [revision, setRevision] = useState(0);

    const chooseProvider = (id: number | null) => {
        const provider = props.providers.find(
            (candidate) => candidate.id === id,
        );

        form.setData((data) => ({
            ...data,
            provider_id: id,
            from_name: data.from_name || provider?.from_name || '',
            from_email: data.from_email || provider?.from_email || '',
            reply_to: data.reply_to || provider?.reply_to || '',
        }));
    };

    const providerOptions = props.providers.map((provider) => (
        <option key={provider.id} value={provider.id}>
            {provider.name} ({provider.type})
        </option>
    ));

    return (
        <div className="space-y-6">
            <EmailEnvelope
                rows={[
                    {
                        name: 'from',
                        label: 'From',
                        error: errors.from_name ?? errors.from_email,
                        content: (
                            <div className="flex min-w-0 flex-1 items-center gap-0.5 text-sm">
                                <input
                                    aria-label="From name"
                                    value={form.data.from_name}
                                    size={Math.max(
                                        form.data.from_name.length,
                                        10,
                                    )}
                                    placeholder="Your name or company"
                                    onChange={(event) =>
                                        form.setData(
                                            'from_name',
                                            event.target.value,
                                        )
                                    }
                                    className={cn(
                                        envelopeInput,
                                        'max-w-56 font-medium placeholder:font-normal',
                                    )}
                                />
                                <span className="text-muted-foreground">
                                    &lt;
                                </span>
                                <input
                                    aria-label="From email"
                                    type="email"
                                    value={form.data.from_email}
                                    size={Math.max(
                                        form.data.from_email.length,
                                        17,
                                    )}
                                    placeholder="hello@example.com"
                                    onChange={(event) =>
                                        form.setData(
                                            'from_email',
                                            event.target.value,
                                        )
                                    }
                                    className={cn(
                                        envelopeInput,
                                        'max-w-72 text-muted-foreground focus:text-foreground',
                                    )}
                                />
                                <span className="text-muted-foreground">
                                    &gt;
                                </span>
                            </div>
                        ),
                    },
                    {
                        name: 'reply_to',
                        label: 'Reply-to',
                        value: form.data.reply_to,
                        onChange: (reply) => form.setData('reply_to', reply),
                        placeholder: 'Replies go to the from address',
                        error: errors.reply_to,
                        muted: true,
                    },
                    {
                        name: 'provider',
                        label: 'Send through',
                        error: errors.provider_id,
                        content: (
                            <select
                                aria-label="Send through"
                                value={form.data.provider_id ?? ''}
                                onChange={(event) =>
                                    chooseProvider(
                                        event.target.value
                                            ? Number(event.target.value)
                                            : null,
                                    )
                                }
                                className={cn(
                                    envelopeInput,
                                    '-ml-1 flex-1 cursor-pointer rounded-md px-1 hover:bg-muted/60',
                                    !form.data.provider_id &&
                                        'text-muted-foreground',
                                )}
                            >
                                <option value="">Choose a provider</option>
                                {providerOptions}
                            </select>
                        ),
                    },
                    {
                        name: 'subject',
                        label: 'Subject',
                        value: form.data.subject,
                        onChange: (subject) => form.setData('subject', subject),
                        placeholder: "What's new this month",
                        error: errors.subject,
                        mergeTags: props.mergeTags,
                    },
                    {
                        name: 'preheader',
                        label: 'Preview text',
                        value: form.data.preheader,
                        onChange: (preheader) =>
                            form.setData('preheader', preheader),
                        placeholder: 'The line inboxes show after the subject',
                        error: errors.preheader,
                        muted: true,
                    },
                ]}
            />

            <div className="space-y-3">
                <EmailContentEditor
                    key={revision}
                    content={{
                        editor: form.data.editor,
                        state:
                            form.data.editor_state === ''
                                ? null
                                : JSON.parse(form.data.editor_state),
                        html: form.data.html,
                    }}
                    mergeTags={props.mergeTags}
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
                    actions={
                        <>
                            <TemplateDialog
                                onChoose={(template) => {
                                    form.setData((data) => ({
                                        ...data,
                                        subject:
                                            template.subject ?? data.subject,
                                        preheader:
                                            template.preheader ??
                                            data.preheader,
                                        editor: template.editor,
                                        editor_state:
                                            template.editor_state == null
                                                ? ''
                                                : JSON.stringify(
                                                      template.editor_state,
                                                  ),
                                        html: templateHtml(template),
                                    }));
                                    setRevision((current) => current + 1);
                                }}
                            />
                            <SaveTemplateDialog
                                defaultName={form.data.name}
                                content={{
                                    subject: form.data.subject,
                                    preheader: form.data.preheader,
                                    editor: form.data.editor,
                                    editor_state:
                                        form.data.editor_state === ''
                                            ? null
                                            : JSON.parse(
                                                  form.data.editor_state,
                                              ),
                                    html: form.data.html,
                                }}
                            />
                        </>
                    }
                />
                <InputError message={errors.html} />
            </div>

            <CampaignOptions
                form={form}
                errors={errors}
                mergeTags={props.mergeTags}
            />
        </div>
    );
}

/**
 * Settings that shape how the campaign is sent, as rows with switches: tracking, an A/B test and the plain-text
 * version.
 */
function CampaignOptions({
    form,
    errors,
    mergeTags,
}: {
    form: DraftForm;
    errors: Record<string, string | undefined>;
    mergeTags: MergeTag[];
}) {
    const [ownText, setOwnText] = useState(form.data.text !== '');

    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <header className="space-y-1 border-b px-5 py-4 sm:px-6">
                <h2 className="text-base font-semibold">Options</h2>
                <p className="text-sm text-muted-foreground">
                    Tracking, testing and the plain-text version.
                </p>
            </header>
            <div className="divide-y">
                <SettingRow
                    id="track-clicks"
                    title="Track clicks"
                    description="Links pass through a short redirect so the report shows who clicked what."
                    control={
                        <ToggleSwitch
                            id="track-clicks"
                            checked={form.data.track_clicks}
                            onCheckedChange={(checked) =>
                                form.setData('track_clicks', checked)
                            }
                        />
                    }
                />
                <SettingRow
                    id="track-opens"
                    title="Track opens"
                    description="A tiny invisible image counts opens. It's approximate: some inboxes block images and others load them on their own."
                    control={
                        <ToggleSwitch
                            id="track-opens"
                            checked={form.data.track_opens}
                            onCheckedChange={(checked) =>
                                form.setData('track_opens', checked)
                            }
                        />
                    }
                />
                <SettingRow
                    id="ab-test"
                    title="A/B test"
                    description="Send two versions to a sample first, then send the better one to everyone else."
                    control={
                        <ToggleSwitch
                            id="ab-test"
                            checked={form.data.ab_enabled}
                            onCheckedChange={(checked) =>
                                form.setData('ab_enabled', checked)
                            }
                        />
                    }
                >
                    {form.data.ab_enabled && (
                        <AbTestPanel
                            form={form}
                            errors={errors}
                            mergeTags={mergeTags}
                        />
                    )}
                </SettingRow>
                <SettingRow
                    title="Plain-text version"
                    description={
                        ownText
                            ? 'Your own version goes out alongside the email, for inboxes that only show text.'
                            : "Made from the email when it's sent, for inboxes that only show text."
                    }
                    control={
                        ownText ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    form.setData('text', '');
                                    setOwnText(false);
                                }}
                            >
                                Use automatic
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setOwnText(true)}
                            >
                                Write my own
                            </Button>
                        )
                    }
                >
                    {ownText && (
                        <textarea
                            aria-label="Plain text"
                            value={form.data.text}
                            autoFocus
                            onChange={(event) =>
                                form.setData('text', event.target.value)
                            }
                            className="min-h-48 w-full rounded-md border border-input bg-card p-3 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    )}
                </SettingRow>
            </div>
        </section>
    );
}

function SettingRow({
    id,
    title,
    description,
    control,
    children,
}: {
    id?: string;
    title: string;
    description: string;
    control: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="space-y-4 px-5 py-4 sm:px-6">
            <div className="flex items-start justify-between gap-6">
                <div className="space-y-0.5">
                    <label htmlFor={id} className="text-sm font-medium">
                        {title}
                    </label>
                    <p className="max-w-xl text-13 text-muted-foreground">
                        {description}
                    </p>
                </div>
                <div className="shrink-0 pt-0.5">{control}</div>
            </div>
            {children}
        </div>
    );
}

function AbTestPanel({
    form,
    errors,
    mergeTags,
}: {
    form: DraftForm;
    errors: Record<string, string | undefined>;
    mergeTags: MergeTag[];
}) {
    const testsContent = form.data.ab_html_b !== '';

    return (
        <div className="space-y-5 rounded-lg border bg-muted/30 p-4">
            <div className="grid content-start gap-2">
                <Label>What to test</Label>
                <div className="inline-flex w-fit items-center gap-1 rounded-lg bg-muted p-0.5">
                    {(
                        [
                            [false, 'Subject line'],
                            [true, 'Content'],
                        ] as const
                    ).map(([content, label]) => (
                        <button
                            key={label}
                            type="button"
                            aria-pressed={testsContent === content}
                            onClick={() =>
                                form.setData((data) => ({
                                    ...data,
                                    // Version B's content starts as a copy of A, ready to change.
                                    ab_html_b: content ? data.html : '',
                                    ab_editor_state_b: content
                                        ? data.editor_state
                                        : '',
                                }))
                            }
                            className={cn(
                                'h-7 rounded-md px-3 text-13 font-medium text-muted-foreground',
                                testsContent === content &&
                                    'bg-background text-foreground shadow-xs',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <Field
                label="Subject B"
                error={errors.ab_subject_b}
                hint={
                    testsContent
                        ? 'Optional when testing content. Leave it empty to keep the same subject.'
                        : `Version A is "${form.data.subject || 'no subject yet'}".`
                }
            >
                <Input
                    value={form.data.ab_subject_b}
                    onChange={(event) =>
                        form.setData('ab_subject_b', event.target.value)
                    }
                    className="bg-card"
                />
            </Field>

            {testsContent && (
                <div className="grid content-start gap-2">
                    <Label>Content B</Label>
                    <EmailContentEditor
                        content={{
                            editor: form.data.ab_editor_state_b
                                ? 'blocks'
                                : 'html',
                            state:
                                form.data.ab_editor_state_b === ''
                                    ? null
                                    : JSON.parse(form.data.ab_editor_state_b),
                            html: form.data.ab_html_b,
                        }}
                        mergeTags={mergeTags}
                        onChange={(content) =>
                            form.setData((data) => ({
                                ...data,
                                ab_html_b: content.html,
                                ab_editor_state_b:
                                    content.state === null
                                        ? ''
                                        : JSON.stringify(content.state),
                            }))
                        }
                    />
                </div>
            )}

            <div className="grid gap-4 md:grid-cols-3">
                <Field label="Test on" error={errors.ab_sample_percent}>
                    <NativeSelect
                        value={form.data.ab_sample_percent}
                        onChange={(event) =>
                            form.setData(
                                'ab_sample_percent',
                                Number(event.target.value),
                            )
                        }
                    >
                        {[10, 20, 30, 40, 50].map((share) => (
                            <option key={share} value={share}>
                                {share}% of the audience
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    label="Pick the winner after"
                    error={errors.ab_wait_minutes}
                >
                    <NativeSelect
                        value={form.data.ab_wait_minutes}
                        onChange={(event) =>
                            form.setData(
                                'ab_wait_minutes',
                                Number(event.target.value),
                            )
                        }
                    >
                        {[60, 120, 240, 480, 1440].map((minutes) => (
                            <option key={minutes} value={minutes}>
                                {minutes < 1440
                                    ? `${minutes / 60} hours`
                                    : '1 day'}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field label="Winner by" error={errors.ab_metric}>
                    <NativeSelect
                        value={form.data.ab_metric}
                        onChange={(event) =>
                            form.setData(
                                'ab_metric',
                                event.target.value as 'clicks' | 'opens',
                            )
                        }
                    >
                        <option value="clicks">Clicks (recommended)</option>
                        <option value="opens">Opens (approximate)</option>
                    </NativeSelect>
                </Field>
            </div>
        </div>
    );
}

function AudienceStep({
    form,
    errors,
    props,
}: {
    form: DraftForm;
    errors: Record<string, string | undefined>;
    props: Props;
}) {
    const sizes = props.audienceSizes;
    const [search, setSearch] = useState('');
    const type = form.data.audience_type;
    const lists = props.lists.filter((list) =>
        list.name.toLowerCase().includes(search.trim().toLowerCase()),
    );

    const toggleList = (id: number) =>
        form.setData(
            'list_ids',
            form.data.list_ids.includes(id)
                ? form.data.list_ids.filter((listId) => listId !== id)
                : [...form.data.list_ids, id],
        );

    const choices = [
        {
            value: 'all' as const,
            icon: Users,
            title: 'Everyone',
            description: 'Every subscribed contact',
            size: sizes?.everyone,
        },
        {
            value: 'lists' as const,
            icon: ListIcon,
            title: 'Lists',
            description: 'People on one or more lists',
            size: undefined,
        },
        {
            value: 'segment' as const,
            icon: Filter,
            title: 'A segment',
            description: 'People matching saved rules',
            size: undefined,
        },
    ];

    return (
        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div className="space-y-6">
                <section className="space-y-3">
                    <h2 className="text-base font-semibold">
                        Who should get it?
                    </h2>
                    <div
                        role="radiogroup"
                        aria-label="Audience"
                        className="grid gap-3 sm:grid-cols-3"
                    >
                        {choices.map((choice) => (
                            <ChoiceCard
                                key={choice.value}
                                selected={type === choice.value}
                                onSelect={() =>
                                    form.setData('audience_type', choice.value)
                                }
                                icon={choice.icon}
                                title={choice.title}
                                description={choice.description}
                                meta={
                                    choice.size !== undefined
                                        ? `${choice.size.toLocaleString()} people`
                                        : undefined
                                }
                            />
                        ))}
                    </div>
                </section>

                {type === 'lists' && (
                    <section className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-semibold">
                                Choose lists
                            </h2>
                            {props.lists.length > 6 && (
                                <Input
                                    type="search"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Find a list"
                                    aria-label="Find a list"
                                    className="h-8 w-56"
                                />
                            )}
                        </div>
                        {props.lists.length === 0 ? (
                            <EmptyChoice
                                message="No lists yet. Make one, then add contacts to it."
                                href={listsIndex()}
                                action="Go to lists"
                            />
                        ) : (
                            <div className="grid gap-2 sm:grid-cols-2">
                                {lists.map((list) => (
                                    <OptionRow
                                        key={list.id}
                                        role="checkbox"
                                        selected={form.data.list_ids.includes(
                                            list.id,
                                        )}
                                        onSelect={() => toggleList(list.id)}
                                        title={list.name}
                                        size={sizes?.lists[list.id]}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                )}

                {type === 'segment' && (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">
                            Choose a segment
                        </h2>
                        {props.segments.length === 0 ? (
                            <EmptyChoice
                                message="No segments yet. A segment picks contacts by their details, tags and lists."
                                href={createSegment()}
                                action="Create a segment"
                            />
                        ) : (
                            <div
                                role="radiogroup"
                                aria-label="Segment"
                                className="grid gap-2 sm:grid-cols-2"
                            >
                                {props.segments.map((segment) => (
                                    <OptionRow
                                        key={segment.id}
                                        role="radio"
                                        selected={
                                            form.data.segment_id === segment.id
                                        }
                                        onSelect={() =>
                                            form.setData(
                                                'segment_id',
                                                segment.id,
                                            )
                                        }
                                        title={segment.name}
                                        size={sizes?.segments[segment.id]}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                )}

                <InputError message={errors.list_ids ?? errors.segment_id} />
            </div>

            <aside className="space-y-4 rounded-xl border bg-card p-5 shadow-xs lg:sticky lg:top-[calc(var(--marketing-header-height,4rem)+5.5rem)]">
                <div className="space-y-1">
                    <p className="text-13 text-muted-foreground">
                        Will receive it
                    </p>
                    {props.audienceCount === undefined ||
                    form.isDirty ||
                    form.processing ? (
                        <p className="flex h-9 items-center gap-2 text-sm text-muted-foreground">
                            <Spinner /> Counting…
                        </p>
                    ) : (
                        <p className="text-3xl font-semibold tabular-nums">
                            {props.audienceCount.toLocaleString()}
                            <span className="ml-1.5 text-sm font-normal text-muted-foreground">
                                {props.audienceCount === 1
                                    ? 'person'
                                    : 'people'}
                            </span>
                        </p>
                    )}
                    <p className="text-13 text-muted-foreground">
                        {audienceSummary(form.data, props)}
                    </p>
                </div>
                <ul className="space-y-2 border-t pt-4 text-13 text-muted-foreground">
                    <li className="flex gap-2">
                        <Check className="mt-0.5 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        Unsubscribed, archived and suppressed contacts are left
                        out.
                    </li>
                    <li className="flex gap-2">
                        <Check className="mt-0.5 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        Someone on more than one list gets it once.
                    </li>
                </ul>
            </aside>
        </div>
    );
}

/** The chosen audience in a few words, such as "Newsletter and Customers". */
function audienceSummary(data: Draft, props: Props): string {
    if (data.audience_type === 'all') {
        return 'Everyone subscribed';
    }

    if (data.audience_type === 'segment') {
        return (
            props.segments.find((segment) => segment.id === data.segment_id)
                ?.name ?? 'No segment chosen yet'
        );
    }

    const names = props.lists
        .filter((list) => data.list_ids.includes(list.id))
        .map((list) => list.name);

    if (names.length === 0) {
        return 'No lists chosen yet';
    }

    return names.length === 1
        ? names[0]
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

function ChoiceCard({
    selected,
    onSelect,
    icon: Icon,
    title,
    description,
    meta,
}: {
    selected: boolean;
    onSelect: () => void;
    icon: LucideIcon;
    title: string;
    description: string;
    meta?: string;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onSelect}
            className={cn(
                'relative flex flex-col items-start gap-3 rounded-xl border bg-card p-4 text-left shadow-xs transition-[border-color,box-shadow] outline-none hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                selected && 'border-foreground ring-1 ring-foreground',
            )}
        >
            <span
                className={cn(
                    'flex size-8 items-center justify-center rounded-lg border bg-background text-muted-foreground',
                    selected &&
                        'border-foreground bg-foreground text-background',
                )}
            >
                <Icon className="size-4" />
            </span>
            <span className="space-y-0.5">
                <span className="block text-sm font-semibold">{title}</span>
                <span className="block text-13 text-muted-foreground">
                    {description}
                </span>
            </span>
            {meta && (
                <span className="text-xs text-muted-foreground tabular-nums">
                    {meta}
                </span>
            )}
        </button>
    );
}

function OptionRow({
    role,
    selected,
    onSelect,
    title,
    size,
}: {
    role: 'checkbox' | 'radio';
    selected: boolean;
    onSelect: () => void;
    title: string;
    size: number | undefined;
}) {
    return (
        <button
            type="button"
            role={role}
            aria-checked={selected}
            onClick={onSelect}
            className={cn(
                'flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 text-left shadow-xs transition-[border-color,box-shadow] outline-none hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                selected && 'border-foreground ring-1 ring-foreground',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'flex size-4 shrink-0 items-center justify-center border transition-colors',
                    role === 'radio' ? 'rounded-full' : 'rounded-[4px]',
                    selected
                        ? 'border-foreground bg-foreground text-background'
                        : 'border-input bg-background',
                )}
            >
                {selected &&
                    (role === 'radio' ? (
                        <span className="size-1.5 rounded-full bg-background" />
                    ) : (
                        <Check className="size-3" />
                    ))}
            </span>
            <span className="min-w-0 flex-1 truncate text-sm font-medium">
                {title}
            </span>
            <span className="shrink-0 text-13 text-muted-foreground tabular-nums">
                {size === undefined ? '…' : size.toLocaleString()}
            </span>
        </button>
    );
}

function EmptyChoice({
    message,
    href,
    action,
}: {
    message: string;
    href: ReturnType<typeof listsIndex>;
    action: string;
}) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-10 text-center">
            <p className="max-w-sm text-sm text-muted-foreground">{message}</p>
            <Button variant="outline" size="sm" asChild>
                <Link href={href}>{action}</Link>
            </Button>
        </div>
    );
}

/** Checklist items fixed outside the campaign, and where. */
const fixedElsewhere: Record<string, ReturnType<typeof sendingEdit>> = {
    paused: sendingEdit(),
    webhooks: providersIndex(),
};

/** Which step fixes a checklist item, for its "Fix" link. */
function stepFor(key: string): (typeof steps)[number] | null {
    return (
        (Object.keys(stepChecks) as (typeof steps)[number][]).find((step) =>
            stepChecks[step].includes(key),
        ) ?? null
    );
}

function ReviewStep({
    form,
    props,
    canSend,
    canApprove,
    saving,
    goTo,
}: {
    form: DraftForm;
    props: Props;
    canSend: boolean;
    canApprove: boolean;
    saving: boolean;
    goTo: (step: (typeof steps)[number]) => void;
}) {
    const { campaign, checklist } = props;
    const provider = props.providers.find(
        (candidate) => candidate.id === form.data.provider_id,
    );
    const problems = (checklist ?? []).filter(
        (item) => item.blocking && !item.passed,
    );
    const warnings = (checklist ?? []).filter(
        (item) => !item.blocking && !item.passed,
    );
    const passed = (checklist ?? []).filter((item) => item.passed);
    const blocked = !checklist || problems.length > 0;
    const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');

    return (
        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div className="space-y-6">
                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <SummaryRow
                        label="To"
                        onEdit={() => goTo('Audience')}
                        value={
                            <>
                                {props.audienceCount === 0 ? (
                                    <Missing>
                                        {audienceSummary(form.data, props)}
                                    </Missing>
                                ) : (
                                    audienceSummary(form.data, props)
                                )}
                                <span className="text-muted-foreground">
                                    {props.audienceCount !== undefined &&
                                        ` · ${props.audienceCount.toLocaleString()} ${props.audienceCount === 1 ? 'person' : 'people'}`}
                                </span>
                            </>
                        }
                    />
                    <SummaryRow
                        label="From"
                        onEdit={() => goTo('Content')}
                        value={
                            form.data.from_email ? (
                                <>
                                    {form.data.from_name}{' '}
                                    <span className="text-muted-foreground">
                                        &lt;{form.data.from_email}&gt;
                                    </span>
                                </>
                            ) : (
                                <Missing>No sender yet</Missing>
                            )
                        }
                    />
                    <SummaryRow
                        label="Subject"
                        onEdit={() => goTo('Content')}
                        value={
                            form.data.subject || (
                                <Missing>No subject yet</Missing>
                            )
                        }
                    />
                    <SummaryRow
                        label="Preview text"
                        onEdit={() => goTo('Content')}
                        value={
                            form.data.preheader || (
                                <span className="text-muted-foreground">
                                    None, so inboxes show the first line
                                </span>
                            )
                        }
                    />
                    <SummaryRow
                        label="Send through"
                        onEdit={() => goTo('Content')}
                        value={
                            provider?.name ?? <Missing>No provider yet</Missing>
                        }
                    />
                    <SummaryRow
                        label="Tracking"
                        onEdit={() => goTo('Content')}
                        value={
                            [
                                form.data.track_clicks && 'Clicks',
                                form.data.track_opens && 'opens',
                            ]
                                .filter(Boolean)
                                .join(' and ') || 'Off'
                        }
                    />
                </section>

                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="flex items-center justify-between gap-2 border-b px-4 py-2.5">
                        <h2 className="text-sm font-semibold">
                            What people will get
                        </h2>
                        <div className="flex items-center gap-1 rounded-lg bg-muted p-0.5">
                            {(['desktop', 'mobile'] as const).map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    aria-pressed={device === option}
                                    onClick={() => setDevice(option)}
                                    className={cn(
                                        'flex h-7 items-center gap-1.5 rounded-md px-2.5 text-13 font-medium text-muted-foreground capitalize',
                                        device === option &&
                                            'bg-background text-foreground shadow-xs',
                                    )}
                                >
                                    {option === 'desktop' ? (
                                        <Monitor className="size-3.5" />
                                    ) : (
                                        <Smartphone className="size-3.5" />
                                    )}
                                    {option}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="flex justify-center bg-muted/40 p-4">
                        <iframe
                            title="Email preview"
                            sandbox=""
                            srcDoc={form.data.html}
                            className="h-[36rem] rounded-md border bg-white transition-[width]"
                            style={{
                                width: device === 'mobile' ? 375 : '100%',
                            }}
                        />
                    </div>
                </section>
            </div>

            <aside className="space-y-4 lg:sticky lg:top-[calc(var(--marketing-header-height,4rem)+5.5rem)]">
                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="border-b px-4 py-3">
                        {!checklist ? (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Spinner /> Checking…
                            </p>
                        ) : problems.length > 0 ? (
                            <p className="flex items-center gap-2 text-sm font-semibold">
                                <CircleAlert className="size-4 text-red-600 dark:text-red-400" />
                                {problems.length === 1
                                    ? '1 thing to fix before sending'
                                    : `${problems.length} things to fix before sending`}
                            </p>
                        ) : (
                            <p className="flex items-center gap-2 text-sm font-semibold">
                                <CircleCheck className="size-4 text-emerald-600 dark:text-emerald-400" />
                                Ready to send
                            </p>
                        )}
                    </div>
                    {checklist && (
                        <ul className="divide-y">
                            {[...problems, ...warnings].map((item) => {
                                const fixIn = stepFor(item.key);

                                return (
                                    <li
                                        key={item.key}
                                        className="flex gap-2.5 px-4 py-3"
                                    >
                                        {item.blocking ? (
                                            <CircleAlert className="mt-0.5 size-4 shrink-0 text-red-600 dark:text-red-400" />
                                        ) : (
                                            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                        )}
                                        <div className="min-w-0 flex-1 space-y-0.5">
                                            <p className="text-13 font-medium">
                                                {item.label}
                                            </p>
                                            {item.message && (
                                                <p className="text-xs text-muted-foreground">
                                                    {item.message}
                                                </p>
                                            )}
                                        </div>
                                        {fixIn ? (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="-my-1 h-7 shrink-0"
                                                onClick={() => goTo(fixIn)}
                                            >
                                                Fix
                                            </Button>
                                        ) : (
                                            fixedElsewhere[item.key] && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="-my-1 h-7 shrink-0"
                                                    asChild
                                                >
                                                    <Link
                                                        href={
                                                            fixedElsewhere[
                                                                item.key
                                                            ]
                                                        }
                                                    >
                                                        Fix
                                                    </Link>
                                                </Button>
                                            )
                                        )}
                                    </li>
                                );
                            })}
                            {passed.length > 0 && (
                                <li>
                                    <details className="group px-4 py-3">
                                        <summary className="flex cursor-pointer list-none items-center gap-2.5 text-13 text-muted-foreground">
                                            <CircleCheck className="size-4 text-emerald-600 dark:text-emerald-400" />
                                            {passed.length} checks passed
                                            <ChevronDown className="ml-auto size-4 transition-transform group-open:rotate-180" />
                                        </summary>
                                        <ul className="mt-2 space-y-1.5 pl-6.5">
                                            {passed.map((item) => (
                                                <li
                                                    key={item.key}
                                                    className="text-13 text-muted-foreground"
                                                >
                                                    {item.label}
                                                </li>
                                            ))}
                                        </ul>
                                    </details>
                                </li>
                            )}
                        </ul>
                    )}
                </section>

                {props.requiresApproval && (
                    <section className="space-y-3 rounded-xl border bg-card p-4 shadow-xs">
                        <h2 className="text-sm font-semibold">Approval</h2>
                        {campaign.approved_at ? (
                            <p className="text-13 text-muted-foreground">
                                Approved {formatDateTime(campaign.approved_at)}.
                                Editing the content withdraws the approval.
                            </p>
                        ) : canApprove ? (
                            <Button
                                variant="outline"
                                className="w-full"
                                disabled={saving}
                                onClick={() =>
                                    router.post(
                                        CampaignController.approve.url(
                                            campaign.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Approve campaign
                            </Button>
                        ) : (
                            <p className="text-13 text-muted-foreground">
                                Someone with permission to approve campaigns
                                needs to approve this one.
                            </p>
                        )}
                    </section>
                )}

                {canSend && campaign.status === 'draft' && (
                    <SendPanel
                        campaignId={campaign.id}
                        audienceCount={props.audienceCount}
                        disabled={blocked || saving}
                    />
                )}

                <TestPanel campaignId={campaign.id} disabled={saving} />
            </aside>
        </div>
    );
}

function SummaryRow({
    label,
    value,
    onEdit,
}: {
    label: string;
    value: ReactNode;
    onEdit: () => void;
}) {
    return (
        <div className="flex items-center gap-3 border-b px-4 py-3 last:border-b-0 sm:px-5">
            <span className="w-28 shrink-0 text-13 text-muted-foreground">
                {label}
            </span>
            <span className="min-w-0 flex-1 truncate text-sm">{value}</span>
            <Button
                variant="ghost"
                size="sm"
                className="-my-1 h-7 shrink-0 text-muted-foreground"
                onClick={onEdit}
                aria-label={`Edit ${label.toLowerCase()}`}
            >
                <Pencil />
            </Button>
        </div>
    );
}

function Missing({ children }: { children: ReactNode }) {
    return <span className="text-red-600 dark:text-red-400">{children}</span>;
}

/**
 * Send now, after a confirmation, or schedule for later. The button says how many people it goes to.
 */
function SendPanel({
    campaignId,
    audienceCount,
    disabled,
}: {
    campaignId: number;
    audienceCount: number | undefined;
    disabled: boolean;
}) {
    const [mode, setMode] = useState<'now' | 'later'>('now');
    const [sendAt, setSendAt] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const people =
        audienceCount === undefined
            ? 'everyone'
            : `${audienceCount.toLocaleString()} ${audienceCount === 1 ? 'person' : 'people'}`;

    const submit = () => {
        setProcessing(true);
        router.post(
            CampaignController.schedule.url(campaignId),
            {
                // The picker gives local time without a zone; send it as an absolute time.
                send_at:
                    mode === 'later' && sendAt
                        ? new Date(sendAt).toISOString()
                        : null,
            },
            {
                preserveScroll: true,
                onError: (failed) => setErrors(failed),
                onFinish: () => {
                    setProcessing(false);
                    setConfirming(false);
                },
            },
        );
    };

    return (
        <section className="space-y-4 rounded-xl border bg-card p-4 shadow-xs">
            <div className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-0.5">
                {(['now', 'later'] as const).map((option) => (
                    <button
                        key={option}
                        type="button"
                        aria-pressed={mode === option}
                        onClick={() => setMode(option)}
                        className={cn(
                            'flex h-8 items-center justify-center gap-1.5 rounded-md text-13 font-medium text-muted-foreground',
                            mode === option &&
                                'bg-background text-foreground shadow-xs',
                        )}
                    >
                        {option === 'now' ? (
                            <Send className="size-3.5" />
                        ) : (
                            <CalendarClock className="size-3.5" />
                        )}
                        {option === 'now' ? 'Send now' : 'Schedule'}
                    </button>
                ))}
            </div>

            {mode === 'later' && (
                <div className="grid content-start gap-2">
                    <Label htmlFor="send-at">Send on</Label>
                    <Input
                        id="send-at"
                        type="datetime-local"
                        value={sendAt}
                        onChange={(event) => setSendAt(event.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        In your time zone. You can unschedule it until then.
                    </p>
                </div>
            )}

            <InputError message={errors.send_at ?? errors.campaign} />

            <Button
                className="w-full"
                disabled={
                    disabled || processing || (mode === 'later' && !sendAt)
                }
                onClick={() =>
                    mode === 'now' ? setConfirming(true) : submit()
                }
            >
                {processing && <Spinner />}
                {mode === 'now'
                    ? `Send to ${people}`
                    : sendAt
                      ? `Schedule for ${formatDateTime(new Date(sendAt).toISOString())}`
                      : 'Choose a time'}
            </Button>
            {disabled && (
                <p className="text-center text-xs text-muted-foreground">
                    Fix the checks above to send.
                </p>
            )}

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogTitle>Send to {people} now?</DialogTitle>
                    <DialogDescription>
                        It starts going out straight away and can't be called
                        back once delivered.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Not yet</Button>
                        </DialogClose>
                        <Button disabled={processing} onClick={submit}>
                            {processing && <Spinner />}
                            Send now
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

/**
 * Send a test to up to five addresses, separated by commas, starting with your own.
 */
function TestPanel({
    campaignId,
    disabled,
}: {
    campaignId: number;
    disabled: boolean;
}) {
    const ownEmail = (
        usePage().props as { auth?: { user?: { email?: string } } }
    ).auth?.user?.email;
    const [emails, setEmails] = useState(ownEmail ?? '');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const send = () => {
        setProcessing(true);
        setError(undefined);
        router.post(
            CampaignController.test.url(campaignId),
            {
                emails: emails
                    .split(/[\s,;]+/)
                    .map((email) => email.trim())
                    .filter(Boolean),
            },
            {
                preserveScroll: true,
                onError: (failed) =>
                    setError(Object.values(failed)[0] as string | undefined),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <section className="space-y-3 rounded-xl border bg-card p-4 shadow-xs">
            <div className="space-y-0.5">
                <h2 className="text-sm font-semibold">Send a test</h2>
                <p className="text-xs text-muted-foreground">
                    Tracking is off and the unsubscribe link does nothing.
                </p>
            </div>
            <div className="flex gap-2">
                <Input
                    value={emails}
                    onChange={(event) => setEmails(event.target.value)}
                    placeholder="you@company.com, team@company.com"
                    aria-label="Test addresses"
                />
                <Button
                    variant="outline"
                    disabled={disabled || processing || emails.trim() === ''}
                    onClick={send}
                >
                    {processing && <Spinner />}
                    Send
                </Button>
            </div>
            <InputError message={error} />
        </section>
    );
}

function Field({
    label,
    error,
    hint,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

EditCampaign.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
