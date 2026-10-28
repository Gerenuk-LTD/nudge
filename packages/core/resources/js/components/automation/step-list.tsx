import { ArrowDown, Plus, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { AutomationStep, StepStats, StepType } from '@marketing/types';
import { newStep, stepLabels, summarize } from './steps';

type Props = {
    steps: AutomationStep[];
    onChange: (steps: AutomationStep[]) => void;
    onSelect: (id: string) => void;
    selectedId: string | null;
    stats: Record<string, StepStats>;
    readOnly: boolean;
    depth?: number;
};

/**
 * A vertical list of steps with an "add" point between each. Conditions show their two branches side by side.
 */
export function StepList({
    steps,
    onChange,
    onSelect,
    selectedId,
    stats,
    readOnly,
    depth = 0,
}: Props) {
    const insertAt = (index: number, type: StepType) => {
        const step = newStep(type);
        onChange([...steps.slice(0, index), step, ...steps.slice(index)]);
        onSelect(step.id);
    };

    return (
        <div className="flex flex-col items-center gap-2">
            {!readOnly && (
                <AddStep
                    onAdd={(type) => insertAt(0, type)}
                    allowCondition={depth < 4}
                />
            )}

            {steps.map((step, index) => (
                <div
                    key={step.id}
                    className="flex w-full flex-col items-center gap-2"
                >
                    <StepCard
                        step={step}
                        stats={stats[step.id]}
                        selected={selectedId === step.id}
                        readOnly={readOnly}
                        onSelect={() => onSelect(step.id)}
                        onRemove={() =>
                            onChange(
                                steps.filter(
                                    (candidate) => candidate.id !== step.id,
                                ),
                            )
                        }
                    />

                    {step.type === 'condition' && (
                        <div className="grid w-full gap-4 md:grid-cols-2">
                            {(['yes', 'no'] as const).map((branch) => (
                                <div
                                    key={branch}
                                    className="rounded-xl border border-dashed p-3"
                                >
                                    <p className="mb-2 text-center text-xs font-medium text-muted-foreground uppercase">
                                        {branch === 'yes'
                                            ? 'If it matches'
                                            : 'If not'}
                                    </p>
                                    <StepList
                                        steps={step[branch] ?? []}
                                        onChange={(children) =>
                                            onChange(
                                                steps.map((candidate) =>
                                                    candidate.id === step.id
                                                        ? {
                                                              ...candidate,
                                                              [branch]:
                                                                  children,
                                                          }
                                                        : candidate,
                                                ),
                                            )
                                        }
                                        onSelect={onSelect}
                                        selectedId={selectedId}
                                        stats={stats}
                                        readOnly={readOnly}
                                        depth={depth + 1}
                                    />
                                </div>
                            ))}
                        </div>
                    )}

                    {readOnly ? (
                        index < steps.length - 1 && (
                            <ArrowDown className="size-4 text-muted-foreground" />
                        )
                    ) : (
                        <AddStep
                            onAdd={(type) => insertAt(index + 1, type)}
                            allowCondition={depth < 4}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

function StepCard({
    step,
    stats,
    selected,
    readOnly,
    onSelect,
    onRemove,
}: {
    step: AutomationStep;
    stats?: StepStats;
    selected: boolean;
    readOnly: boolean;
    onSelect: () => void;
    onRemove: () => void;
}) {
    return (
        <div
            className={cn(
                'group flex w-full max-w-md items-start gap-3 rounded-xl border bg-background p-3 text-left shadow-xs transition-colors',
                selected && 'border-primary ring-2 ring-primary/20',
            )}
        >
            <button
                type="button"
                onClick={onSelect}
                className="min-w-0 flex-1 text-left"
            >
                <p className="text-sm font-medium">{stepLabels[step.type]}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {summarize(step)}
                </p>
                {stats && (
                    <div className="mt-2 flex flex-wrap gap-1">
                        {stats.waiting ? (
                            <Badge variant="secondary">
                                {stats.waiting} here now
                            </Badge>
                        ) : null}
                        {stats.sent !== undefined && (
                            <Badge variant="outline">{stats.sent} sent</Badge>
                        )}
                        {stats.clicked ? (
                            <Badge variant="outline">
                                {stats.clicked} clicked
                            </Badge>
                        ) : null}
                    </div>
                )}
            </button>
            {!readOnly && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7 opacity-0 group-hover:opacity-100 focus:opacity-100"
                    aria-label="Remove step"
                    onClick={onRemove}
                >
                    <Trash2 className="size-4" />
                </Button>
            )}
        </div>
    );
}

function AddStep({
    onAdd,
    allowCondition,
}: {
    onAdd: (type: StepType) => void;
    allowCondition: boolean;
}) {
    const types = (Object.keys(stepLabels) as StepType[]).filter(
        (type) => allowCondition || type !== 'condition',
    );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-7 rounded-full"
                    aria-label="Add a step"
                >
                    <Plus className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
                {types.map((type) => (
                    <DropdownMenuItem key={type} onSelect={() => onAdd(type)}>
                        {stepLabels[type]}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
