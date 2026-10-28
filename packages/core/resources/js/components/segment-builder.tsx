import { Plus, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { NativeSelect } from '@marketing/components/native-select';
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
};

const valuelessOperators = ['is_empty', 'is_not_empty', 'is_true', 'is_false'];

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

    return field.options[0]?.value ?? '';
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

    return (
        <div
            className={cn(
                'space-y-3 rounded-xl border p-4',
                depth > 1 && 'bg-muted/30',
            )}
        >
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span>Match</span>
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
                        className="ml-auto"
                        onClick={onRemove}
                    >
                        Remove group
                    </Button>
                )}
            </div>

            {group.conditions.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No conditions: every active contact matches.
                </p>
            )}

            {group.conditions.map((item, index) => {
                const itemPath = `${path}.conditions.${index}`;

                return item.type === 'group' ? (
                    <SegmentGroupEditor
                        key={index}
                        group={item}
                        fields={fields}
                        depth={depth + 1}
                        path={itemPath}
                        errorPaths={errorPaths}
                        onChange={(next) =>
                            update(index, { ...next, type: 'group' })
                        }
                        onRemove={() => remove(index)}
                    />
                ) : (
                    <ConditionEditor
                        key={index}
                        condition={item}
                        fields={fields}
                        invalid={errorPaths.includes(itemPath)}
                        onChange={(next) => update(index, next)}
                        onRemove={() => remove(index)}
                    />
                );
            })}

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        onChange({
                            ...group,
                            conditions: [
                                ...group.conditions,
                                emptyCondition(fields),
                            ],
                        })
                    }
                >
                    <Plus />
                    Condition
                </Button>
                {depth < MAX_DEPTH && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onChange({
                                ...group,
                                conditions: [
                                    ...group.conditions,
                                    {
                                        type: 'group',
                                        match:
                                            group.match === 'all'
                                                ? 'any'
                                                : 'all',
                                        conditions: [emptyCondition(fields)],
                                    },
                                ],
                            })
                        }
                    >
                        <Plus />
                        Group
                    </Button>
                )}
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
                field.type === 'date' || valuelessOperators.includes(operator)
                    ? defaultValue(field, operator)
                    : condition.value,
        });

    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-2 rounded-lg',
                invalid && 'ring-2 ring-red-500/60 ring-offset-2',
            )}
        >
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

            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Remove condition"
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

    if (['within_last_days', 'more_than_days_ago'].includes(operator)) {
        return (
            <div className="flex items-center gap-2 text-sm">
                <Input
                    aria-label="Days"
                    type="number"
                    min={1}
                    className="w-24"
                    value={value ?? ''}
                    onChange={(event) => onChange(Number(event.target.value))}
                />
                {operator === 'within_last_days' ? 'days' : 'days ago'}
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
            className="w-56"
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
