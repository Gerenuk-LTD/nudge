import type { RequestPayload } from '@inertiajs/core';
import { Head, router } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useRef, useState } from 'react';
import AutomationController from '@/actions/Gerenuk/Marketing/Http/Controllers/AutomationController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index as automationsIndex } from '@/routes/marketing/automations';
import type { StepEditorOptions } from '@marketing/components/automation/step-editor';
import { StepEditor } from '@marketing/components/automation/step-editor';
import { FlowCanvas } from '@marketing/components/automation/flow-canvas';
import {
    findStep,
    insertStep,
    newStep,
    removeStep,
    stepLabels,
    text,
    updateStep,
} from '@marketing/components/automation/steps';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { NativeSelect } from '@/components/ui/native-select';
import { EditorBar } from '@marketing/components/editor-bar';
import { PageBody } from '@marketing/components/page-header';
import { StatusBadge } from '@marketing/components/status-badge';
import {
    useSaveShortcut,
    useUnsavedChangesGuard,
} from '@marketing/hooks/use-editor';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDateTime } from '@marketing/lib/format';
import type { AutomationStep, StepStats } from '@marketing/types';

type Automation = {
    id: number;
    name: string;
    status: 'draft' | 'active' | 'paused';
    trigger_type: string;
    trigger_config: Record<string, unknown>;
    allow_reentry: boolean;
    definition: { steps: AutomationStep[] };
    entered_count: number;
    completed_count: number;
    approved_at: string | null;
};

type Props = StepEditorOptions & {
    automation: Automation;
    requiresApproval: boolean;
    problems: string[];
    stepStats: Record<string, StepStats>;
    triggers: { value: string; label: string }[];
    segments: { id: number; name: string }[];
    dateFields: { key: string; label: string }[];
};

export default function EditAutomation(props: Props) {
    const { automation } = props;
    const can = useCan();
    const readOnly = !can('automations.manage');
    const canApprove = can('campaigns.approve');

    const [name, setName] = useState(automation.name);
    const [triggerType, setTriggerType] = useState(automation.trigger_type);
    const [triggerConfig, setTriggerConfig] = useState<Record<string, unknown>>(
        automation.trigger_config,
    );
    const [allowReentry, setAllowReentry] = useState(automation.allow_reentry);
    const [steps, setSteps] = useState<AutomationStep[]>(
        automation.definition.steps,
    );
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [dirty, setDirty] = useState(false);
    const version = useRef(0);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const change =
        <T,>(setter: (value: T) => void) =>
        (value: T) => {
            setter(value);
            version.current += 1;
            setDirty(true);
        };

    const selected = findStep(steps, selectedId);

    /** Everything stopping it from starting, including save errors that belong to settings in a closed panel. */
    const problems = [
        ...props.problems,
        ...[
            errors.automation,
            errors.name,
            errors.definition,
            ...Object.entries(errors)
                .filter(([key]) => key.startsWith('trigger_config.'))
                .map(([, message]) => message),
        ].filter((message): message is string => Boolean(message)),
    ];

    const save = (then?: () => void) => {
        const savedVersion = version.current;

        setSaving(true);
        router.put(
            AutomationController.update.url(automation.id),
            // Inertia's payload type cannot describe free-form JSON like the step tree, which is plain data.
            {
                name,
                trigger_type: triggerType,
                trigger_config: triggerConfig,
                allow_reentry: allowReentry,
                definition: { steps },
            } as unknown as RequestPayload,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setDirty(version.current !== savedVersion);
                    setErrors({});
                    then?.();
                },
                onError: (failed) => setErrors(failed),
                onFinish: () => setSaving(false),
            },
        );
    };

    useSaveShortcut(() => dirty && !saving && save(), !readOnly);
    useUnsavedChangesGuard(dirty || saving);

    const activate = () =>
        save(() =>
            router.post(
                AutomationController.activate.url(automation.id),
                {},
                {
                    preserveScroll: true,
                    onError: (failed) => setErrors(failed),
                },
            ),
        );

    return (
        <>
            <Head title={name} />

            <PageBody>
                <EditorBar
                    back={{ href: automationsIndex(), label: 'automations' }}
                    title={name || 'Untitled automation'}
                    saveState={
                        readOnly
                            ? undefined
                            : saving
                              ? 'saving'
                              : Object.keys(errors).length > 0
                                ? 'error'
                                : dirty
                                  ? 'unsaved'
                                  : 'saved'
                    }
                    meta={
                        <>
                            <StatusBadge
                                tone={
                                    automation.status === 'active'
                                        ? 'success'
                                        : automation.status === 'paused'
                                          ? 'warning'
                                          : 'neutral'
                                }
                                live={automation.status === 'active'}
                            >
                                {automation.status === 'active'
                                    ? 'Live'
                                    : automation.status === 'paused'
                                      ? 'Paused'
                                      : 'Draft'}
                            </StatusBadge>
                            <span>
                                {automation.entered_count.toLocaleString()}{' '}
                                entered ·{' '}
                                {automation.completed_count.toLocaleString()}{' '}
                                finished
                            </span>
                        </>
                    }
                    actions={
                        <>
                            {!readOnly && (
                                <>
                                    {automation.status !== 'active' && (
                                        <ConfirmDelete
                                            url={AutomationController.destroy.url(
                                                automation.id,
                                            )}
                                            title={`Delete ${automation.name}?`}
                                            description="The automation and everyone's progress through it are deleted. Emails already sent stay on contacts' timelines."
                                        />
                                    )}
                                    <Button
                                        variant="outline"
                                        disabled={saving || !dirty}
                                        onClick={() => save()}
                                    >
                                        {saving && <Spinner />}
                                        Save
                                    </Button>
                                    {automation.status === 'active' ? (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    AutomationController.pause.url(
                                                        automation.id,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Pause
                                        </Button>
                                    ) : (
                                        <Button
                                            disabled={saving}
                                            onClick={activate}
                                        >
                                            {automation.status === 'paused'
                                                ? 'Resume'
                                                : 'Start automation'}
                                        </Button>
                                    )}
                                </>
                            )}
                        </>
                    }
                />

                {automation.status === 'active' && dirty && (
                    <p className="text-sm text-amber-700 dark:text-amber-400">
                        {props.requiresApproval && !canApprove
                            ? 'This automation is live. Saving changes to its emails or trigger pauses it until someone approves them.'
                            : 'This automation is live. Saved changes apply to contacts already in it; anyone waiting on a removed step leaves the automation.'}
                    </p>
                )}

                {props.requiresApproval && (
                    <section className="space-y-3 rounded-xl border bg-card p-4 shadow-xs">
                        <h2 className="text-sm font-semibold">Approval</h2>
                        {automation.approved_at ? (
                            <p className="text-13 text-muted-foreground">
                                Approved{' '}
                                {formatDateTime(automation.approved_at)}.
                                Changing its emails or trigger withdraws the
                                approval.
                            </p>
                        ) : canApprove ? (
                            <>
                                <Button
                                    variant="outline"
                                    disabled={saving || dirty}
                                    onClick={() =>
                                        router.post(
                                            AutomationController.approve.url(
                                                automation.id,
                                            ),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Approve automation
                                </Button>
                                {dirty && (
                                    <p className="text-13 text-muted-foreground">
                                        Save your changes first.
                                    </p>
                                )}
                            </>
                        ) : (
                            <p className="text-13 text-muted-foreground">
                                Someone with permission to approve campaigns
                                needs to approve this automation before it goes
                                live.
                            </p>
                        )}
                    </section>
                )}

                {problems.length > 0 && automation.status !== 'active' && (
                    <div className="space-y-1.5 rounded-xl border bg-card p-4 shadow-xs">
                        <p className="text-sm font-medium">
                            Before it can start
                        </p>
                        {problems.map((problem) => (
                            <p
                                key={problem}
                                className="flex items-start gap-2 text-13 text-muted-foreground"
                            >
                                <CircleAlert className="mt-0.5 size-4 shrink-0 text-amber-600" />
                                {problem}
                            </p>
                        ))}
                    </div>
                )}

                <FlowCanvas
                    steps={steps}
                    trigger={{
                        title: 'Trigger',
                        summary: triggerSummary(
                            triggerType,
                            triggerConfig,
                            props,
                        ),
                    }}
                    live={automation.status === 'active'}
                    readOnly={readOnly}
                    selectedId={selectedId}
                    stats={props.stepStats}
                    onSelect={setSelectedId}
                    onInsert={(at, type) => {
                        const step = newStep(type);
                        change(setSteps)(insertStep(steps, at, step));
                        setSelectedId(step.id);
                    }}
                    onRemove={(id) => {
                        change(setSteps)(removeStep(steps, id));
                        setSelectedId((current) =>
                            current === id ? null : current,
                        );
                    }}
                />
            </PageBody>

            <Sheet
                open={selectedId === 'trigger' || selected !== null}
                onOpenChange={(open) => !open && setSelectedId(null)}
            >
                <SheetContent
                    className={cn(
                        'w-full overflow-y-auto sm:max-w-3xl',
                        selected?.type === 'send_email' && 'sm:max-w-5xl',
                    )}
                >
                    {selectedId === 'trigger' ? (
                        <>
                            <SheetHeader>
                                <SheetTitle>Trigger and settings</SheetTitle>
                                <SheetDescription>
                                    What starts the automation and who can go
                                    through it.
                                </SheetDescription>
                            </SheetHeader>
                            <fieldset
                                disabled={readOnly}
                                className="grid gap-4 p-4 md:grid-cols-2"
                            >
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="automation-name">
                                        Name
                                    </Label>
                                    <Input
                                        id="automation-name"
                                        value={name}
                                        onChange={(event) =>
                                            change(setName)(event.target.value)
                                        }
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="automation-trigger">
                                        Starts when someone
                                    </Label>
                                    <NativeSelect
                                        id="automation-trigger"
                                        value={triggerType}
                                        onChange={(event) => {
                                            change(setTriggerType)(
                                                event.target.value,
                                            );
                                            setTriggerConfig({});
                                        }}
                                    >
                                        {props.triggers.map((trigger) => (
                                            <option
                                                key={trigger.value}
                                                value={trigger.value}
                                            >
                                                {trigger.label.toLowerCase()}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                                <TriggerSettings
                                    type={triggerType}
                                    config={triggerConfig}
                                    onChange={change(setTriggerConfig)}
                                    props={props}
                                />
                                <label className="flex items-start gap-2 text-sm md:col-span-2">
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={allowReentry}
                                        onCheckedChange={(checked) =>
                                            change(setAllowReentry)(
                                                checked === true,
                                            )
                                        }
                                    />
                                    <span>
                                        Let people go through it more than once
                                        <span className="block text-xs text-muted-foreground">
                                            Nobody is ever in it twice at the
                                            same time.
                                        </span>
                                    </span>
                                </label>
                            </fieldset>
                        </>
                    ) : (
                        selected && (
                            <>
                                <SheetHeader>
                                    <SheetTitle>
                                        {stepLabels[selected.type]}
                                    </SheetTitle>
                                </SheetHeader>
                                <div className="p-4">
                                    <StepEditor
                                        step={selected}
                                        options={props}
                                        readOnly={readOnly}
                                        onChange={(config) =>
                                            change(setSteps)(
                                                updateStep(
                                                    steps,
                                                    selected.id,
                                                    (step) => ({
                                                        ...step,
                                                        config,
                                                    }),
                                                ),
                                            )
                                        }
                                    />
                                    {Object.entries(errors)
                                        .filter(([key]) =>
                                            key.startsWith('definition.'),
                                        )
                                        .map(([key, message]) => (
                                            <InputError
                                                key={key}
                                                message={message}
                                            />
                                        ))}
                                </div>
                            </>
                        )
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

/**
 * The trigger node's one-line description, such as "Joins a list: Newsletter".
 */
function triggerSummary(
    type: string,
    config: Record<string, unknown>,
    props: Props,
): string {
    const label =
        props.triggers.find((trigger) => trigger.value === type)?.label ?? type;
    const named = (options: { id: number; name: string }[], key: string) =>
        options.find((option) => option.id === config[key])?.name;
    const target =
        type === 'list_joined'
            ? named(props.lists, 'list_id')
            : type === 'tag_added'
              ? named(props.tags, 'tag_id')
              : type === 'segment_entered'
                ? named(props.segments, 'segment_id')
                : type === 'api_event'
                  ? text(config.event)
                  : undefined;

    return target ? `${label}: ${target}` : label;
}

function TriggerSettings({
    type,
    config,
    onChange,
    props,
}: {
    type: string;
    config: Record<string, unknown>;
    onChange: (config: Record<string, unknown>) => void;
    props: Props;
}) {
    const set = (key: string, value: unknown) =>
        onChange({ ...config, [key]: value });
    const value = (key: string) => text(config[key]);
    const optionSelect = (
        key: string,
        label: string,
        options: { id: number; name: string }[],
    ) => (
        <div className="grid content-start gap-2">
            <Label>{label}</Label>
            <NativeSelect
                value={value(key)}
                onChange={(event) =>
                    set(
                        key,
                        event.target.value ? Number(event.target.value) : null,
                    )
                }
            >
                <option value="">Choose…</option>
                {options.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.name}
                    </option>
                ))}
            </NativeSelect>
        </div>
    );

    switch (type) {
        case 'list_joined':
            return optionSelect('list_id', 'List', props.lists);
        case 'tag_added':
            return optionSelect('tag_id', 'Tag', props.tags);
        case 'segment_entered':
            return (
                <>
                    {optionSelect('segment_id', 'Segment', props.segments)}
                    <label className="flex items-start gap-2 text-sm md:col-span-2">
                        <Checkbox
                            className="mt-0.5"
                            checked={config.include_existing === true}
                            onCheckedChange={(checked) =>
                                set('include_existing', checked === true)
                            }
                        />
                        <span>
                            Include people already in the segment when it starts
                            <span className="block text-xs text-muted-foreground">
                                Otherwise only people who join the segment
                                afterwards enter. Checked every 5 minutes.
                            </span>
                        </span>
                    </label>
                </>
            );
        case 'date_field':
            return (
                <>
                    <div className="grid content-start gap-2">
                        <Label>Date field</Label>
                        <NativeSelect
                            value={value('field_key')}
                            onChange={(event) =>
                                set('field_key', event.target.value)
                            }
                        >
                            <option value="">Choose…</option>
                            {props.dateFields.map((field) => (
                                <option key={field.key} value={field.key}>
                                    {field.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid content-start gap-2">
                        <Label>Start</Label>
                        <div className="flex items-center gap-2 text-sm">
                            <Input
                                type="number"
                                className="w-24"
                                value={value('offset_days') || '0'}
                                onChange={(event) =>
                                    set(
                                        'offset_days',
                                        Number(event.target.value),
                                    )
                                }
                            />
                            days before the date,
                            <NativeSelect
                                value={value('repeat') || 'yearly'}
                                onChange={(event) =>
                                    set('repeat', event.target.value)
                                }
                            >
                                <option value="yearly">every year</option>
                                <option value="once">once</option>
                            </NativeSelect>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Checked each morning at 08:00. Use 0 for the day
                            itself.
                        </p>
                    </div>
                </>
            );
        case 'inactivity':
            return (
                <div className="grid content-start gap-2">
                    <Label>Days without opening or clicking</Label>
                    <Input
                        type="number"
                        min={7}
                        value={value('days') || '90'}
                        onChange={(event) =>
                            set('days', Number(event.target.value))
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Only people who were sent something in that time.
                        Checked each morning.
                    </p>
                </div>
            );
        case 'api_event':
            return (
                <div className="grid content-start gap-2">
                    <Label>Event name</Label>
                    <Input
                        placeholder="signed_up"
                        value={value('event')}
                        onChange={(event) => set('event', event.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        Your app sends <code>POST /api/v1/events</code> with an
                        email and this event name.
                    </p>
                </div>
            );
        default:
            return null;
    }
}

EditAutomation.layout = {
    breadcrumbs: [{ title: 'Automations', href: automationsIndex() }],
};
