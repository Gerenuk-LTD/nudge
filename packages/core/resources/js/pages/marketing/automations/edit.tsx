import type { RequestPayload } from '@inertiajs/core';
import { Head, router } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useState } from 'react';
import AutomationController from '@/actions/Gerenuk/Marketing/Http/Controllers/AutomationController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { index as automationsIndex } from '@/routes/marketing/automations';
import type { StepEditorOptions } from '@marketing/components/automation/step-editor';
import { StepEditor } from '@marketing/components/automation/step-editor';
import { StepList } from '@marketing/components/automation/step-list';
import {
    findStep,
    stepLabels,
    text,
    updateStep,
} from '@marketing/components/automation/steps';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { NativeSelect } from '@marketing/components/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';
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
};

type Props = StepEditorOptions & {
    automation: Automation;
    problems: string[];
    stepStats: Record<string, StepStats>;
    triggers: { value: string; label: string }[];
    segments: { id: number; name: string }[];
    dateFields: { key: string; label: string }[];
};

export default function EditAutomation(props: Props) {
    const { automation } = props;
    const readOnly = !useCan()('automations.manage');

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
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const change =
        <T,>(setter: (value: T) => void) =>
        (value: T) => {
            setter(value);
            setDirty(true);
        };

    const selected = findStep(steps, selectedId);

    const save = (then?: () => void) => {
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
                    setDirty(false);
                    setErrors({});
                    then?.();
                },
                onError: (failed) => setErrors(failed),
                onFinish: () => setSaving(false),
            },
        );
    };

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
                <PageHeader
                    title={name || 'Untitled automation'}
                    description={`${automation.entered_count.toLocaleString()} entered · ${automation.completed_count.toLocaleString()} finished${dirty ? ' · Unsaved changes' : ''}`}
                    actions={
                        <>
                            <Badge
                                variant={
                                    automation.status === 'active'
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {automation.status}
                            </Badge>
                            {!readOnly && (
                                <>
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
                                    {automation.status !== 'active' && (
                                        <ConfirmDelete
                                            url={AutomationController.destroy.url(
                                                automation.id,
                                            )}
                                            title={`Delete ${automation.name}?`}
                                            description="The automation and everyone's progress through it are deleted. Emails already sent stay on contacts' timelines."
                                        />
                                    )}
                                </>
                            )}
                        </>
                    }
                />

                {automation.status === 'active' && dirty && (
                    <p className="text-sm text-amber-700 dark:text-amber-400">
                        This automation is live. Saved changes apply to contacts
                        already in it; anyone waiting on a removed step leaves
                        the automation.
                    </p>
                )}

                {(props.problems.length > 0 || errors.automation) &&
                    automation.status !== 'active' && (
                        <div className="space-y-1 rounded-xl border p-4">
                            <p className="text-sm font-medium">
                                Before it can start
                            </p>
                            {[
                                ...props.problems,
                                ...(errors.automation
                                    ? [errors.automation]
                                    : []),
                            ].map((problem) => (
                                <p
                                    key={problem}
                                    className="flex items-start gap-2 text-sm text-muted-foreground"
                                >
                                    <CircleAlert className="mt-0.5 size-4 shrink-0 text-amber-600" />
                                    {problem}
                                </p>
                            ))}
                        </div>
                    )}

                <fieldset
                    disabled={readOnly}
                    className="grid max-w-4xl gap-4 rounded-xl border p-4 md:grid-cols-2"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="automation-name">Name</Label>
                        <Input
                            id="automation-name"
                            value={name}
                            onChange={(event) =>
                                change(setName)(event.target.value)
                            }
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="automation-trigger">
                            Starts when someone
                        </Label>
                        <NativeSelect
                            id="automation-trigger"
                            value={triggerType}
                            onChange={(event) => {
                                change(setTriggerType)(event.target.value);
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
                                change(setAllowReentry)(checked === true)
                            }
                        />
                        <span>
                            Let people go through it more than once
                            <span className="block text-xs text-muted-foreground">
                                Nobody is ever in it twice at the same time.
                            </span>
                        </span>
                    </label>
                    <InputError message={errors.definition} />
                </fieldset>

                <div className="max-w-4xl">
                    <StepList
                        steps={steps}
                        onChange={change(setSteps)}
                        onSelect={setSelectedId}
                        selectedId={selectedId}
                        stats={props.stepStats}
                        readOnly={readOnly}
                    />
                </div>
            </PageBody>

            <Sheet
                open={selected !== null}
                onOpenChange={(open) => !open && setSelectedId(null)}
            >
                <SheetContent className="w-full overflow-y-auto sm:max-w-3xl">
                    {selected && (
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
                                                (step) => ({ ...step, config }),
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
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
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
        <div className="grid gap-2">
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
                    <div className="grid gap-2">
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
                    <div className="grid gap-2">
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
                <div className="grid gap-2">
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
                <div className="grid gap-2">
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
