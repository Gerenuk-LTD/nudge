import { Filter, Plus, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { NativeSelect } from '@/components/ui/native-select';
import { EmptyState } from '@marketing/components/empty-state';
import type {
    SegmentCondition,
    SegmentField,
    SegmentGroup,
    SegmentNode,
} from '@marketing/types';

const MAX_DEPTH = 3;

const operatorLabels: Record<string, string> = {
    equals: 'is',
    not_equals: 'is not',
    contains: 'contains',
    not_contains: 'does not contain',
    starts_with: 'starts with',
    ends_with: 'ends with',
    is_empty: 'is empty',
    is_not_empty: 'is not empty',
    greater_than: 'is greater than',
    less_than: 'is less than',
    before: 'is before',
    after: 'is after',
    within_last_days: 'is within the last',
    more_than_days_ago: 'is more than',
    is_true: 'is yes',
    is_false: 'is no or not set',
    is: 'is',
    is_not: 'is not',
    has: 'has',
    has_not: 'does not have',
    in: 'is on',
    not_in: 'is not on',
    in_last_days: 'in the last',
    not_in_last_days: 'not in the last',
    never: 'never',
    received: 'received',
    not_received: "didn't receive",
    opened: 'opened',
    not_opened: "didn't open",
    clicked: 'clicked',
    not_clicked: "didn't click",
};

const valuelessOperators = [
    'is_empty',
    'is_not_empty',
    'is_true',
    'is_false',
    'never',
];

const activityOperators = ['in_last_days', 'not_in_last_days'];

export function emptyCondition(fields: SegmentField[]): SegmentCondition {
    const field = fields[0];

    return {
        type: 'condition',
        field: field.key,
        operator: field.operators[0],
        value: defaultValue(field, field.operators[0]),
    };
}

function defaultValue(field: SegmentField, operator: string) {
    if (valuelessOperators.includes(operator)) {
        return null;
    }

    if (['within_last_days', 'more_than_days_ago'].includes(operator)) {
        return 30;
    }

    if (activityOperators.includes(operator)) {
        return 90;
    }

    return field.options[0]?.value ?? '';
}

/** One condition in words, such as "Tag has customer" or "Date added is within the last 30 days". */
export function describeCondition(
    condition: SegmentCondition,
    fields: SegmentField[],
): string {
    const field = fields.find((candidate) => candidate.key === condition.field);
    const operator = operatorLabels[condition.operator] ?? condition.operator;
    const label = field?.label ?? condition.field;

    if (valuelessOperators.includes(condition.operator)) {
        return `${label} ${operator}`;
    }

    if (condition.operator === 'within_last_days') {
        return `${label} ${operator} ${condition.value} days`;
    }

    if (activityOperators.includes(condition.operator)) {
        return `${label} ${operator} ${condition.value} days`;
    }

    if (condition.operator === 'more_than_days_ago') {
        return `${label} ${operator} ${condition.value} days ago`;
    }

    const option = field?.options.find(
        (candidate) => String(candidate.value) === String(condition.value),
    );

    const value = option
        ? field?.type === 'campaign'
            ? `“${option.label}”`
            : option.label
        : field?.type === 'number' || field?.type === 'date'
          ? String(condition.value ?? '')
          : `“${condition.value ?? ''}”`;

    return `${label} ${operator} ${value}`;
}

/** Each top-level rule in words, with nested groups in brackets, for summing up a segment in a line or two. */
export function describeGroup(
    group: SegmentGroup,
    fields: SegmentField[],
): string[] {
    return group.conditions.map((item) =>
        item.type === 'group'
            ? `(${describeGroup(item, fields).join(item.match === 'all' ? ' and ' : ' or ')})`
            : describeCondition(item, fields),
    );
}

export type SegmentStarter = {
    key: string;
    name: string;
    description: string;
    definition: SegmentGroup;
};

/**
 * Common segments to start from, using this workspace's own tags and lists when it has some.
 */
export function segmentStarters(fields: SegmentField[]): SegmentStarter[] {
    const option = (key: string) =>
        fields.find((field) => field.key === key)?.options[0];
    const tag = option('tag');
    const list = option('list');
    const starter = (
        key: string,
        name: string,
        description: string,
        condition: Omit<SegmentCondition, 'type'>,
    ): SegmentStarter => ({
        key,
        name,
        description,
        definition: {
            match: 'all',
            conditions: [{ type: 'condition', ...condition }],
        },
    });

    return [
        starter('new', 'New contacts', 'Added in the last 30 days', {
            field: 'created_at',
            operator: 'within_last_days',
            value: 30,
        }),
        starter(
            'engaged',
            'Engaged recently',
            'Opened an email in the last 90 days',
            {
                field: 'activity:opened',
                operator: 'in_last_days',
                value: 90,
            },
        ),
        ...(tag
            ? [
                  starter(
                      'tag',
                      `Tagged ${tag.label}`,
                      `Has the ${tag.label} tag`,
                      {
                          field: 'tag',
                          operator: 'has',
                          value: tag.value,
                      },
                  ),
              ]
            : []),
        ...(list
            ? [
                  starter(
                      'list',
                      `On ${list.label}`,
                      `Members of the ${list.label} list`,
                      {
                          field: 'list',
                          operator: 'in',
                          value: list.value,
                      },
                  ),
              ]
            : []),
        starter(
            'imported',
            'Imported contacts',
            'Came in through a CSV import',
            {
                field: 'source',
                operator: 'is',
                value: 'import',
            },
        ),
    ];
}

export function SegmentGroupEditor({
    group,
    fields,
    onChange,
    onRemove,
    depth = 1,
    errorPaths = [],
    path = 'definition',
}: {
    group: SegmentGroup;
    fields: SegmentField[];
    onChange: (group: SegmentGroup) => void;
    onRemove?: () => void;
    depth?: number;
    errorPaths?: string[];
    path?: string;
}) {
    const update = (index: number, item: SegmentNode) =>
        onChange({
            ...group,
            conditions: group.conditions.map((existing, i) =>
                i === index ? item : existing,
            ),
        });

    const remove = (index: number) =>
        onChange({
            ...group,
            conditions: group.conditions.filter((_, i) => i !== index),
        });

    const join = group.match === 'all' ? 'and' : 'or';
    const add = (item: SegmentNode) =>
        onChange({ ...group, conditions: [...group.conditions, item] });
    const addGroup = () =>
        add({
            type: 'group',
            match: group.match === 'all' ? 'any' : 'all',
            conditions: [emptyCondition(fields)],
        });

    if (depth === 1 && group.conditions.length === 0) {
        return (
            <EmptyState
                icon={Filter}
                title="No conditions yet"
                description="Without conditions, the segment includes every active contact. Add one to narrow it down."
                actions={
                    <Button
                        type="button"
                        size="sm"
                        className="mt-1"
                        onClick={() => add(emptyCondition(fields))}
                    >
                        <Plus />
                        Add a condition
                    </Button>
                }
            />
        );
    }

    return (
        <div
            className={cn(
                'rounded-xl border',
                depth > 1 ? 'bg-muted/30' : 'bg-card shadow-xs',
            )}
        >
            <div
                className={cn(
                    'flex flex-wrap items-center gap-2 border-b text-sm',
                    depth > 1 ? 'px-3 py-2' : 'px-5 py-3',
                )}
            >
                <span>{depth > 1 ? 'Match' : 'Contacts who match'}</span>
                <NativeSelect
                    aria-label="Match type"
                    value={group.match}
                    onChange={(event) =>
                        onChange({
                            ...group,
                            match: event.target.value as 'all' | 'any',
                        })
                    }
                    className="h-8"
                >
                    <option value="all">all</option>
                    <option value="any">any</option>
                </NativeSelect>
                <span>of these conditions</span>
                {onRemove && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="ml-auto text-muted-foreground"
                        onClick={onRemove}
                    >
                        Remove group
                    </Button>
                )}
            </div>

            <div className={cn('space-y-3', depth > 1 ? 'p-3' : 'p-5')}>
                {group.conditions.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No conditions in this group yet.
                    </p>
                )}

                {group.conditions.map((item, index) => {
                    const itemPath = `${path}.conditions.${index}`;

                    return (
                        <div key={index} className="flex items-start gap-3">
                            <span
                                className={cn(
                                    'mt-2 w-12 shrink-0 text-right text-xs font-medium',
                                    index === 0
                                        ? 'text-muted-foreground'
                                        : 'text-foreground',
                                )}
                            >
                                {index === 0 ? 'Where' : join}
                            </span>
                            <div className="min-w-0 flex-1">
                                {item.type === 'group' ? (
                                    <SegmentGroupEditor
                                        group={item}
                                        fields={fields}
                                        depth={depth + 1}
                                        path={itemPath}
                                        errorPaths={errorPaths}
                                        onChange={(next) =>
                                            update(index, {
                                                ...next,
                                                type: 'group',
                                            })
                                        }
                                        onRemove={() => remove(index)}
                                    />
                                ) : (
                                    <ConditionEditor
                                        condition={item}
                                        fields={fields}
                                        invalid={errorPaths.includes(itemPath)}
                                        onChange={(next) => update(index, next)}
                                        onRemove={() => remove(index)}
                                    />
                                )}
                            </div>
                        </div>
                    );
                })}

                <div className="flex flex-wrap gap-2 pl-15">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => add(emptyCondition(fields))}
                    >
                        <Plus />
                        Condition
                    </Button>
                    {depth < MAX_DEPTH && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={addGroup}
                        >
                            <Plus />
                            Group
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

function ConditionEditor({
    condition,
    fields,
    invalid,
    onChange,
    onRemove,
}: {
    condition: SegmentCondition;
    fields: SegmentField[];
    invalid: boolean;
    onChange: (condition: SegmentCondition) => void;
    onRemove: () => void;
}) {
    const field =
        fields.find((candidate) => candidate.key === condition.field) ??
        fields[0];

    const changeField = (key: string) => {
        const next = fields.find((candidate) => candidate.key === key)!;

        onChange({
            type: 'condition',
            field: key,
            operator: next.operators[0],
            value: defaultValue(next, next.operators[0]),
        });
    };

    const changeOperator = (operator: string) =>
        onChange({
            ...condition,
            operator,
            value:
                field.type === 'date' ||
                field.type === 'activity' ||
                valuelessOperators.includes(operator)
                    ? defaultValue(field, operator)
                    : condition.value,
        });

    return (
        <div
            className={cn(
                'flex items-start gap-1 rounded-lg',
                invalid && 'ring-2 ring-red-500/60 ring-offset-2',
            )}
        >
            {/* Three even columns, so the parts of every condition line up and the remove button never wraps. */}
            <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-3">
                <NativeSelect
                    aria-label="Field"
                    value={field.key}
                    onChange={(event) => changeField(event.target.value)}
                >
                    {fields.map((candidate) => (
                        <option key={candidate.key} value={candidate.key}>
                            {candidate.label}
                        </option>
                    ))}
                </NativeSelect>

                <NativeSelect
                    aria-label="Comparison"
                    value={condition.operator}
                    onChange={(event) => changeOperator(event.target.value)}
                >
                    {field.operators.map((operator) => (
                        <option key={operator} value={operator}>
                            {operatorLabels[operator] ?? operator}
                        </option>
                    ))}
                </NativeSelect>

                <ValueInput
                    field={field}
                    operator={condition.operator}
                    value={condition.value ?? null}
                    onChange={(value) => onChange({ ...condition, value })}
                />
            </div>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Remove condition"
                className="shrink-0 text-muted-foreground"
                onClick={onRemove}
            >
                <X />
            </Button>
        </div>
    );
}

function ValueInput({
    field,
    operator,
    value,
    onChange,
}: {
    field: SegmentField;
    operator: string;
    value: string | number | null;
    onChange: (value: string | number | null) => void;
}) {
    if (valuelessOperators.includes(operator)) {
        return null;
    }

    if (
        [
            'within_last_days',
            'more_than_days_ago',
            ...activityOperators,
        ].includes(operator)
    ) {
        return (
            <div className="flex items-center gap-2 text-sm">
                <Input
                    aria-label="Days"
                    type="number"
                    min={1}
                    className="min-w-0 flex-1"
                    value={value ?? ''}
                    onChange={(event) => onChange(Number(event.target.value))}
                />
                {operator === 'more_than_days_ago' ? 'days ago' : 'days'}
            </div>
        );
    }

    if (field.options.length > 0) {
        return (
            <NativeSelect
                aria-label="Value"
                value={String(value ?? '')}
                onChange={(event) => onChange(event.target.value)}
            >
                {field.options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </NativeSelect>
        );
    }

    return (
        <Input
            aria-label="Value"
            type={
                field.type === 'number'
                    ? 'number'
                    : field.type === 'date'
                      ? 'date'
                      : 'text'
            }
            step={field.type === 'number' ? 'any' : undefined}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}
