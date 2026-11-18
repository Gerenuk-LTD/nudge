import { useState } from 'react';
import { text } from './steps';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmailContentEditor } from '@nudge/components/email-editors';
import type { MergeTag } from '@nudge/components/email-editors';
import { NativeSelect } from '@/components/ui/native-select';
import { SegmentGroupEditor } from '@nudge/components/segment-builder';
import { TagInput } from '@nudge/components/tag-input';
import {
    TemplateDialog,
    templateHtml,
} from '@nudge/components/template-picker';
import type {
    AutomationStep,
    CustomField,
    Option,
    SegmentField,
    SegmentGroup,
} from '@nudge/types';

export type StepEditorOptions = {
    providers: {
        id: number;
        name: string;
        from_name: string | null;
        from_email: string | null;
        reply_to: string | null;
    }[];
    lists: Option[];
    tags: Option[];
    customFields: CustomField[];
    segmentFields: SegmentField[];
    mergeTags: MergeTag[];
};

type Props = {
    step: AutomationStep;
    options: StepEditorOptions;
    readOnly: boolean;
    onChange: (config: Record<string, unknown>) => void;
};

export function StepEditor({ step, options, readOnly, onChange }: Props) {
    const config = step.config;
    const set = (key: string, value: unknown) =>
        onChange({ ...config, [key]: value });

    return (
        <fieldset disabled={readOnly} className="space-y-5">
            {step.type === 'send_email' && (
                <EmailStepEditor
                    config={config}
                    set={set}
                    onChange={onChange}
                    options={options}
                />
            )}

            {step.type === 'wait' && (
                <div className="flex items-end gap-2">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="wait-amount">Wait for</Label>
                        <Input
                            id="wait-amount"
                            type="number"
                            min={1}
                            className="w-28"
                            value={text(config.amount) || '1'}
                            onChange={(event) =>
                                set('amount', Number(event.target.value))
                            }
                        />
                    </div>
                    <NativeSelect
                        aria-label="Unit"
                        value={text(config.unit) || 'days'}
                        onChange={(event) => set('unit', event.target.value)}
                    >
                        <option value="minutes">minutes</option>
                        <option value="hours">hours</option>
                        <option value="days">days</option>
                    </NativeSelect>
                </div>
            )}

            {step.type === 'condition' && (
                <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">
                        Contacts who match follow the left branch; everyone else
                        follows the right.
                    </p>
                    <SegmentGroupEditor
                        group={
                            (config.definition as SegmentGroup) ?? {
                                match: 'all',
                                conditions: [],
                            }
                        }
                        fields={options.segmentFields}
                        onChange={(definition) => set('definition', definition)}
                    />
                </div>
            )}

            {step.type === 'update_contact' && (
                <UpdateStepEditor config={config} set={set} options={options} />
            )}

            {step.type === 'webhook' && (
                <div className="grid content-start gap-2">
                    <Label htmlFor="webhook-url">URL</Label>
                    <Input
                        id="webhook-url"
                        type="url"
                        placeholder="https://example.com/hooks/nudge"
                        value={text(config.url)}
                        onChange={(event) => set('url', event.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        We POST the contact's details as JSON. It must be a
                        public https address. A failed call is noted on the
                        contact's run and the automation carries on.
                    </p>
                </div>
            )}

            {step.type === 'exit' && (
                <p className="text-sm text-muted-foreground">
                    The contact leaves the automation here.
                </p>
            )}
        </fieldset>
    );
}

function EmailStepEditor({
    config,
    set,
    onChange,
    options,
}: {
    config: Record<string, unknown>;
    set: (key: string, value: unknown) => void;
    onChange: (config: Record<string, unknown>) => void;
    options: StepEditorOptions;
}) {
    // Bumped when a template replaces the content, so the editor starts over from it.
    const [revision, setRevision] = useState(0);
    const value = (key: string) => text(config[key]);

    const chooseProvider = (id: number | null) => {
        const provider = options.providers.find(
            (candidate) => candidate.id === id,
        );

        onChange({
            ...config,
            provider_id: id,
            from_name: value('from_name') || provider?.from_name || '',
            from_email: value('from_email') || provider?.from_email || '',
            reply_to: value('reply_to') || provider?.reply_to || '',
        });
    };

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Subject">
                    <Input
                        value={value('subject')}
                        onChange={(event) => set('subject', event.target.value)}
                    />
                </Field>
                <Field label="Preview text">
                    <Input
                        value={value('preheader')}
                        onChange={(event) =>
                            set('preheader', event.target.value)
                        }
                    />
                </Field>
                <Field label="Send through">
                    <NativeSelect
                        value={text(config.provider_id)}
                        onChange={(event) =>
                            chooseProvider(
                                event.target.value
                                    ? Number(event.target.value)
                                    : null,
                            )
                        }
                    >
                        <option value="">Choose a provider</option>
                        {options.providers.map((provider) => (
                            <option key={provider.id} value={provider.id}>
                                {provider.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field label="From name">
                    <Input
                        value={value('from_name')}
                        onChange={(event) =>
                            set('from_name', event.target.value)
                        }
                    />
                </Field>
                <Field label="From email">
                    <Input
                        type="email"
                        value={value('from_email')}
                        onChange={(event) =>
                            set('from_email', event.target.value)
                        }
                    />
                </Field>
                <Field label="Reply-to (optional)">
                    <Input
                        type="email"
                        value={value('reply_to')}
                        onChange={(event) =>
                            set('reply_to', event.target.value)
                        }
                    />
                </Field>
            </div>
            <div className="flex gap-6">
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={config.track_clicks !== false}
                        onCheckedChange={(checked) =>
                            set('track_clicks', checked === true)
                        }
                    />
                    Track clicks
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={config.track_opens !== false}
                        onCheckedChange={(checked) =>
                            set('track_opens', checked === true)
                        }
                    />
                    Track opens
                </label>
            </div>
            <div className="flex justify-end">
                <TemplateDialog
                    onChoose={(template) => {
                        onChange({
                            ...config,
                            subject: template.subject ?? value('subject'),
                            preheader: template.preheader ?? value('preheader'),
                            editor: template.editor,
                            editor_state: template.editor_state ?? null,
                            html: templateHtml(template),
                        });
                        setRevision((current) => current + 1);
                    }}
                />
            </div>
            <EmailContentEditor
                key={revision}
                content={{
                    // Steps made before the builder existed are HTML.
                    editor: value('editor') || 'html',
                    state: config.editor_state ?? null,
                    html: value('html'),
                }}
                mergeTags={options.mergeTags}
                onChange={(content) =>
                    onChange({
                        ...config,
                        editor: content.editor,
                        editor_state: content.state,
                        html: content.html,
                    })
                }
            />
        </>
    );
}

function UpdateStepEditor({
    config,
    set,
    options,
}: {
    config: Record<string, unknown>;
    set: (key: string, value: unknown) => void;
    options: StepEditorOptions;
}) {
    const ids = (key: string) => (config[key] as number[] | undefined) ?? [];
    const fields = (config.fields as Record<string, string> | undefined) ?? {};
    const tagNames = options.tags.map((tag) => tag.name);

    const toggle = (key: string, id: number, checked: boolean) =>
        set(
            key,
            checked
                ? [...ids(key), id]
                : ids(key).filter((value) => value !== id),
        );

    return (
        <>
            <Field label="Add tags">
                <TagInput
                    value={(config.add_tags as string[]) ?? []}
                    onChange={(value) => set('add_tags', value)}
                    suggestions={tagNames}
                />
            </Field>
            <Field label="Remove tags">
                <TagInput
                    value={(config.remove_tags as string[]) ?? []}
                    onChange={(value) => set('remove_tags', value)}
                    suggestions={tagNames}
                    allowCreate={false}
                    placeholder="Search tags"
                />
            </Field>
            {options.lists.length > 0 && (
                <div className="grid gap-4 sm:grid-cols-2">
                    {(['add_list_ids', 'remove_list_ids'] as const).map(
                        (key) => (
                            <div key={key} className="space-y-2">
                                <Label>
                                    {key === 'add_list_ids'
                                        ? 'Add to lists'
                                        : 'Remove from lists'}
                                </Label>
                                {options.lists.map((list) => (
                                    <label
                                        key={list.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={ids(key).includes(list.id)}
                                            onCheckedChange={(checked) =>
                                                toggle(
                                                    key,
                                                    list.id,
                                                    checked === true,
                                                )
                                            }
                                        />
                                        {list.name}
                                    </label>
                                ))}
                            </div>
                        ),
                    )}
                </div>
            )}
            {options.customFields.length > 0 && (
                <div className="space-y-2">
                    <Label>Set fields</Label>
                    {options.customFields.map((field) => (
                        <div
                            key={field.key}
                            className="grid grid-cols-[10rem_1fr] items-center gap-2"
                        >
                            <span className="text-sm">{field.label}</span>
                            <Input
                                placeholder="Leave unchanged"
                                type={
                                    field.type === 'date'
                                        ? 'date'
                                        : field.type === 'number'
                                          ? 'number'
                                          : 'text'
                                }
                                value={fields[field.key] ?? ''}
                                onChange={(event) => {
                                    const next = {
                                        ...fields,
                                        [field.key]: event.target.value,
                                    };

                                    if (event.target.value === '') {
                                        delete next[field.key];
                                    }

                                    set('fields', next);
                                }}
                            />
                        </div>
                    ))}
                </div>
            )}
            <label className="flex items-start gap-2 text-sm">
                <Checkbox
                    className="mt-0.5"
                    checked={config.unsubscribe === true}
                    onCheckedChange={(checked) =>
                        set('unsubscribe', checked === true)
                    }
                />
                <span>
                    Unsubscribe them
                    <span className="block text-xs text-muted-foreground">
                        Use as the last step of a re-engagement flow to stop
                        emailing people who never came back.
                    </span>
                </span>
            </label>
        </>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label>{label}</Label>
            {children}
        </div>
    );
}
