import { Form, Head, router, useForm } from '@inertiajs/react';
import { Check, CircleAlert, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import CampaignController from '@/actions/Gerenuk/Marketing/Http/Controllers/CampaignController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index as campaignsIndex } from '@/routes/marketing/campaigns';
import { editorFor } from '@marketing/components/email-editors';
import type { MergeTag } from '@marketing/components/email-editors';
import { NativeSelect } from '@marketing/components/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
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
    html: string | null;
    text: string | null;
    segment_id: number | null;
    track_opens: boolean;
    track_clicks: boolean;
    ab_enabled: boolean;
    ab_subject_b: string | null;
    ab_html_b: string | null;
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
    ab_sample_percent: number;
    ab_wait_minutes: number;
    ab_metric: 'clicks' | 'opens';
};

const steps = ['Content', 'Audience', 'Review and send'] as const;

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
        ab_sample_percent: campaign.ab_sample_percent,
        ab_wait_minutes: campaign.ab_wait_minutes,
        ab_metric: campaign.ab_metric,
    });

    const scheduled = campaign.status === 'scheduled';
    const errors = form.errors as Record<string, string | undefined>;
    useAutosave(form, campaign.id, scheduled);

    return (
        <>
            <Head title={campaign.name} />

            <PageBody>
                <PageHeader
                    title={form.data.name || 'Untitled campaign'}
                    description={
                        scheduled
                            ? `Scheduled for ${formatDateTime(campaign.scheduled_at)}. Unschedule it to make changes.`
                            : form.processing
                              ? 'Saving…'
                              : form.isDirty
                                ? 'Unsaved changes'
                                : 'Draft saved'
                    }
                    actions={
                        scheduled && (
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
                        )
                    }
                />

                <nav
                    className="flex gap-1 border-b"
                    aria-label="Campaign steps"
                >
                    {steps.map((name, index) => (
                        <button
                            key={name}
                            type="button"
                            onClick={() => setStep(name)}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                step === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {index + 1}. {name}
                        </button>
                    ))}
                </nav>

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

                {step === 'Review and send' && (
                    <ReviewStep
                        props={props}
                        canSend={can('campaigns.send')}
                        canApprove={can('campaigns.approve')}
                        saving={form.isDirty || form.processing}
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
    const Editor = editorFor('html').component;

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

    return (
        <div className="space-y-6">
            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Campaign name" error={errors.name}>
                    <Input
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                </Field>
                <Field label="Send through" error={errors.provider_id}>
                    <NativeSelect
                        value={form.data.provider_id ?? ''}
                        onChange={(event) =>
                            chooseProvider(
                                event.target.value
                                    ? Number(event.target.value)
                                    : null,
                            )
                        }
                    >
                        <option value="">Choose a provider</option>
                        {props.providers.map((provider) => (
                            <option key={provider.id} value={provider.id}>
                                {provider.name} ({provider.type})
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field label="Subject" error={errors.subject}>
                    <Input
                        value={form.data.subject}
                        onChange={(event) =>
                            form.setData('subject', event.target.value)
                        }
                        placeholder="What's new this month"
                    />
                </Field>
                <Field
                    label="Preview text"
                    error={errors.preheader}
                    hint="Shown after the subject in most inboxes."
                >
                    <Input
                        value={form.data.preheader}
                        onChange={(event) =>
                            form.setData('preheader', event.target.value)
                        }
                    />
                </Field>
                <Field label="From name" error={errors.from_name}>
                    <Input
                        value={form.data.from_name}
                        onChange={(event) =>
                            form.setData('from_name', event.target.value)
                        }
                    />
                </Field>
                <Field label="From email" error={errors.from_email}>
                    <Input
                        type="email"
                        value={form.data.from_email}
                        onChange={(event) =>
                            form.setData('from_email', event.target.value)
                        }
                    />
                </Field>
                <Field label="Reply-to (optional)" error={errors.reply_to}>
                    <Input
                        type="email"
                        value={form.data.reply_to}
                        onChange={(event) =>
                            form.setData('reply_to', event.target.value)
                        }
                    />
                </Field>
                <div className="flex items-end gap-6 pb-2">
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={form.data.track_clicks}
                            onCheckedChange={(checked) =>
                                form.setData('track_clicks', checked === true)
                            }
                        />
                        Track clicks
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={form.data.track_opens}
                            onCheckedChange={(checked) =>
                                form.setData('track_opens', checked === true)
                            }
                        />
                        Track opens (approximate)
                    </label>
                </div>
            </div>

            <div className="space-y-2">
                <Label>Content</Label>
                <Editor
                    html={form.data.html}
                    state={null}
                    mergeTags={props.mergeTags}
                    onChange={(html) => form.setData('html', html)}
                />
                <InputError message={errors.html} />
            </div>

            <AbTestSettings
                form={form}
                errors={errors}
                mergeTags={props.mergeTags}
            />

            <details className="rounded-xl border p-4">
                <summary className="cursor-pointer text-sm font-medium">
                    Plain-text version
                </summary>
                <p className="mt-2 text-xs text-muted-foreground">
                    Leave empty to generate it from the HTML when sending.
                </p>
                <textarea
                    aria-label="Plain text"
                    value={form.data.text}
                    onChange={(event) =>
                        form.setData('text', event.target.value)
                    }
                    className="mt-2 min-h-48 w-full rounded-md border border-input bg-transparent p-3 font-mono text-xs"
                />
            </details>
        </div>
    );
}

function AbTestSettings({
    form,
    errors,
    mergeTags,
}: {
    form: DraftForm;
    errors: Record<string, string | undefined>;
    mergeTags: MergeTag[];
}) {
    const Editor = editorFor('html').component;
    const testsContent = form.data.ab_html_b !== '';

    return (
        <section className="space-y-4 rounded-xl border p-4">
            <label className="flex items-start gap-2 text-sm">
                <Checkbox
                    className="mt-0.5"
                    checked={form.data.ab_enabled}
                    onCheckedChange={(checked) =>
                        form.setData('ab_enabled', checked === true)
                    }
                />
                <span>
                    <span className="font-medium">A/B test</span>
                    <span className="block text-xs text-muted-foreground">
                        Send two versions to a sample, then send the better one
                        to everyone else.
                    </span>
                </span>
            </label>

            {form.data.ab_enabled && (
                <>
                    <div className="flex gap-4 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                checked={!testsContent}
                                onChange={() => form.setData('ab_html_b', '')}
                            />
                            Test the subject
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                checked={testsContent}
                                onChange={() =>
                                    form.setData('ab_html_b', form.data.html)
                                }
                            />
                            Test the content
                        </label>
                    </div>

                    <Field
                        label="Subject B"
                        error={errors.ab_subject_b}
                        hint={
                            testsContent
                                ? 'Optional when testing content; leave empty to keep the same subject.'
                                : undefined
                        }
                    >
                        <Input
                            value={form.data.ab_subject_b}
                            onChange={(event) =>
                                form.setData('ab_subject_b', event.target.value)
                            }
                        />
                    </Field>

                    {testsContent && (
                        <div className="space-y-2">
                            <Label>Content B</Label>
                            <Editor
                                html={form.data.ab_html_b}
                                state={null}
                                mergeTags={mergeTags}
                                onChange={(html) =>
                                    form.setData('ab_html_b', html)
                                }
                            />
                        </div>
                    )}

                    <div className="grid gap-4 md:grid-cols-3">
                        <Field
                            label="Test sample"
                            error={errors.ab_sample_percent}
                        >
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
                                        event.target.value as
                                            | 'clicks'
                                            | 'opens',
                                    )
                                }
                            >
                                <option value="clicks">
                                    Clicks (recommended)
                                </option>
                                <option value="opens">
                                    Opens (approximate)
                                </option>
                            </NativeSelect>
                        </Field>
                    </div>
                </>
            )}
        </section>
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
    const toggleList = (id: number, checked: boolean) =>
        form.setData(
            'list_ids',
            checked
                ? [...form.data.list_ids, id]
                : form.data.list_ids.filter((listId) => listId !== id),
        );

    return (
        <div className="max-w-2xl space-y-6">
            <div className="space-y-2">
                {(
                    [
                        ['all', 'Everyone subscribed'],
                        ['lists', 'One or more lists'],
                        ['segment', 'A segment'],
                    ] as const
                ).map(([value, label]) => (
                    <label
                        key={value}
                        className="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="radio"
                            name="audience_type"
                            checked={form.data.audience_type === value}
                            onChange={() =>
                                form.setData('audience_type', value)
                            }
                        />
                        {label}
                    </label>
                ))}
            </div>

            {form.data.audience_type === 'lists' && (
                <div className="grid gap-2 rounded-xl border p-4 sm:grid-cols-2">
                    {props.lists.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No lists yet.
                        </p>
                    )}
                    {props.lists.map((list) => (
                        <label
                            key={list.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                checked={form.data.list_ids.includes(list.id)}
                                onCheckedChange={(checked) =>
                                    toggleList(list.id, checked === true)
                                }
                            />
                            {list.name}
                        </label>
                    ))}
                </div>
            )}

            {form.data.audience_type === 'segment' && (
                <NativeSelect
                    aria-label="Segment"
                    value={form.data.segment_id ?? ''}
                    onChange={(event) =>
                        form.setData(
                            'segment_id',
                            event.target.value
                                ? Number(event.target.value)
                                : null,
                        )
                    }
                >
                    <option value="">Choose a segment</option>
                    {props.segments.map((segment) => (
                        <option key={segment.id} value={segment.id}>
                            {segment.name}
                        </option>
                    ))}
                </NativeSelect>
            )}

            <InputError message={errors.list_ids ?? errors.segment_id} />

            <p className="text-sm">
                {props.audienceCount === undefined ? (
                    <Spinner />
                ) : (
                    <>
                        <strong>{props.audienceCount.toLocaleString()}</strong>{' '}
                        subscribed contacts match. Unsubscribed, archived and
                        suppressed contacts are always left out.
                    </>
                )}
            </p>
        </div>
    );
}

function ReviewStep({
    props,
    canSend,
    canApprove,
    saving,
}: {
    props: Props;
    canSend: boolean;
    canApprove: boolean;
    saving: boolean;
}) {
    const { campaign, checklist } = props;
    const [mode, setMode] = useState<'now' | 'later'>('now');
    const blocked =
        !checklist || checklist.some((item) => item.blocking && !item.passed);

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            <section className="space-y-3">
                <h2 className="font-medium">Checks</h2>
                {!checklist ? (
                    <Spinner />
                ) : (
                    <ul className="space-y-2">
                        {checklist.map((item) => (
                            <li key={item.key} className="flex gap-2 text-sm">
                                {item.passed ? (
                                    <Check className="mt-0.5 size-4 shrink-0 text-green-600" />
                                ) : item.blocking ? (
                                    <CircleAlert className="mt-0.5 size-4 shrink-0 text-red-600" />
                                ) : (
                                    <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600" />
                                )}
                                <span>
                                    {item.label}
                                    {(!item.passed ||
                                        item.key === 'audience') &&
                                        item.message && (
                                            <span className="block text-xs text-muted-foreground">
                                                {item.message}
                                            </span>
                                        )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <div className="space-y-6">
                <section className="space-y-3 rounded-xl border p-4">
                    <h2 className="font-medium">Send a test</h2>
                    <Form
                        {...CampaignController.test.form(campaign.id)}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-start gap-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="min-w-56 flex-1 space-y-1">
                                    <Input
                                        name="emails[]"
                                        type="email"
                                        required
                                        placeholder="you@company.com"
                                        aria-label="Test address"
                                    />
                                    <InputError
                                        message={
                                            errors.emails ?? errors['emails.0']
                                        }
                                    />
                                </div>
                                <Button
                                    variant="outline"
                                    disabled={processing || saving}
                                >
                                    Send test
                                </Button>
                            </>
                        )}
                    </Form>
                    <p className="text-xs text-muted-foreground">
                        Tests have tracking switched off and a non-working
                        unsubscribe link.
                    </p>
                </section>

                {props.requiresApproval && (
                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-medium">Approval</h2>
                        {campaign.approved_at ? (
                            <p className="text-sm">
                                Approved {formatDateTime(campaign.approved_at)}.
                                Editing the content withdraws the approval.
                            </p>
                        ) : canApprove ? (
                            <Button
                                variant="outline"
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
                            <p className="text-sm text-muted-foreground">
                                Someone with permission to approve campaigns
                                needs to approve this one.
                            </p>
                        )}
                    </section>
                )}

                {canSend && campaign.status === 'draft' && (
                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-medium">Send</h2>
                        <Form
                            {...CampaignController.schedule.form(campaign.id)}
                            // The picker gives local time without a zone; send it as an absolute time.
                            transform={(data) => ({
                                ...data,
                                send_at:
                                    typeof data.send_at === 'string' &&
                                    data.send_at
                                        ? new Date(data.send_at).toISOString()
                                        : null,
                            })}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="flex gap-4 text-sm">
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                checked={mode === 'now'}
                                                onChange={() => setMode('now')}
                                            />
                                            Now
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                checked={mode === 'later'}
                                                onChange={() =>
                                                    setMode('later')
                                                }
                                            />
                                            Later
                                        </label>
                                    </div>
                                    {mode === 'later' && (
                                        <Input
                                            name="send_at"
                                            type="datetime-local"
                                            required
                                            aria-label="Send at"
                                        />
                                    )}
                                    <InputError
                                        message={
                                            errors.send_at ?? errors.campaign
                                        }
                                    />
                                    <Button
                                        disabled={
                                            processing || blocked || saving
                                        }
                                    >
                                        {mode === 'now'
                                            ? 'Send campaign'
                                            : 'Schedule campaign'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}
            </div>
        </div>
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
        <div className="grid gap-2">
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
