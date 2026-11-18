import {
    Background,
    BackgroundVariant,
    BaseEdge,
    Controls,
    EdgeLabelRenderer,
    Handle,
    MiniMap,
    Position,
    ReactFlow,
    getBezierPath,
} from '@xyflow/react';
import type { Edge, EdgeProps, Node, NodeProps } from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { Plus, Trash2 } from 'lucide-react';
import { createContext, useContext } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { AutomationStep, StepStats, StepType } from '@marketing/types';
import type { InsertPoint } from './steps';
import { stepKinds as kinds } from './step-kinds';
import { stepLabels, summarize } from './steps';

const NODE_WIDTH = 272;
const NODE_HEIGHT = 76;
const COLUMN_GAP = 40;
const ROW_GAP = 64;
const ADD_SIZE = 32;

/** Conditions can nest this deep before the add menu stops offering another. */
const MAX_DEPTH = 4;

type FlowActions = {
    readOnly: boolean;
    selectedId: string | null;
    stats: Record<string, StepStats>;
    onSelect: (id: string) => void;
    onInsert: (at: InsertPoint, type: StepType) => void;
    onRemove: (id: string) => void;
};

const Actions = createContext<FlowActions | null>(null);

function useActions(): FlowActions {
    const actions = useContext(Actions);

    if (!actions) {
        throw new Error('Flow nodes must render inside <FlowCanvas>.');
    }

    return actions;
}

type StepNodeData = { step: AutomationStep; depth: number };
type TriggerNodeData = { title: string; summary: string };
type AddNodeData = { at: InsertPoint; depth: number };
type InsertEdgeData = { at?: InsertPoint; depth: number };

type FlowNode =
    | Node<StepNodeData, 'step'>
    | Node<TriggerNodeData, 'trigger'>
    | Node<AddNodeData, 'add'>;
type FlowEdge = Edge<InsertEdgeData, 'insert'>;

/**
 * An automation drawn as a flow: the trigger at the top, each step as a card below it, and a condition's
 * two branches side by side. The layout comes from the step tree, so nodes are placed rather than dragged.
 * Clicking a card selects it; the plus buttons on connectors and at the end of each branch add a step there.
 */
export function FlowCanvas({
    steps,
    trigger,
    live,
    ...actions
}: FlowActions & {
    steps: AutomationStep[];
    trigger: TriggerNodeData;
    live: boolean;
}) {
    const { nodes, edges } = layout(steps, trigger, actions.readOnly, live);

    return (
        <Actions.Provider value={actions}>
            <div className="h-[calc(100svh-15rem)] min-h-[34rem] overflow-hidden rounded-xl border bg-muted/30 shadow-xs">
                <ReactFlow<FlowNode, FlowEdge>
                    nodes={nodes}
                    edges={edges}
                    nodeTypes={nodeTypes}
                    edgeTypes={edgeTypes}
                    nodesDraggable={false}
                    nodesConnectable={false}
                    edgesFocusable={false}
                    fitView
                    fitViewOptions={{ padding: 0.2, maxZoom: 1 }}
                    minZoom={0.3}
                    maxZoom={1.5}
                    proOptions={{ hideAttribution: true }}
                    className="automation-flow"
                >
                    <Background
                        variant={BackgroundVariant.Dots}
                        gap={18}
                        size={1.2}
                        color="var(--border)"
                    />
                    <Controls showInteractive={false} />
                    <MiniMap
                        pannable
                        zoomable
                        nodeColor="currentColor"
                        nodeStrokeWidth={0}
                        nodeBorderRadius={6}
                    />
                </ReactFlow>
            </div>
        </Actions.Provider>
    );
}

type Tail = { id: string; handle?: string };

/**
 * Place the trigger, every step and every add point, and connect them top to bottom.
 * Nodes carry their fixed size, so the minimap can draw them without React Flow measuring.
 */
function layout(
    steps: AutomationStep[],
    trigger: TriggerNodeData,
    readOnly: boolean,
    live: boolean,
): { nodes: FlowNode[]; edges: FlowEdge[] } {
    const nodes: FlowNode[] = [];
    const edges: FlowEdge[] = [];

    const connect = (
        from: Tail[],
        to: string,
        at: InsertPoint | undefined,
        depth: number,
    ) =>
        from.forEach((tail, index) =>
            edges.push({
                id: `${tail.id}:${tail.handle ?? ''}->${to}`,
                source: tail.id,
                sourceHandle: tail.handle,
                target: to,
                type: 'insert',
                animated: live,
                // A step joined from both branches of a condition gets one plus button, not two.
                data: { at: index === 0 ? at : undefined, depth },
            }),
        );

    const sequence = (
        list: AutomationStep[],
        centre: number,
        top: number,
        from: Tail[],
        parentId: string | null,
        branch: 'yes' | 'no' | null,
        depth: number,
    ): { tails: Tail[]; bottom: number } => {
        let y = top;
        let tails = from;

        for (const [index, step] of list.entries()) {
            connect(
                tails,
                step.id,
                readOnly ? undefined : { parentId, branch, index },
                depth,
            );
            nodes.push({
                id: step.id,
                type: 'step',
                position: { x: centre - NODE_WIDTH / 2, y },
                width: NODE_WIDTH,
                height: NODE_HEIGHT,
                data: { step, depth },
            });

            if (step.type === 'condition') {
                const yes = step.yes ?? [];
                const no = step.no ?? [];
                const yesWidth = widthOf(yes);
                const noWidth = widthOf(no);
                const left = centre - (yesWidth + COLUMN_GAP + noWidth) / 2;
                const branchTop = y + NODE_HEIGHT + ROW_GAP;

                const yesBranch = sequence(
                    yes,
                    left + yesWidth / 2,
                    branchTop,
                    [{ id: step.id, handle: 'yes' }],
                    step.id,
                    'yes',
                    depth + 1,
                );
                const noBranch = sequence(
                    no,
                    left + yesWidth + COLUMN_GAP + noWidth / 2,
                    branchTop,
                    [{ id: step.id, handle: 'no' }],
                    step.id,
                    'no',
                    depth + 1,
                );

                tails = [...yesBranch.tails, ...noBranch.tails];
                y = Math.max(yesBranch.bottom, noBranch.bottom) + ROW_GAP;
            } else {
                tails = step.type === 'exit' ? [] : [{ id: step.id }];
                y += NODE_HEIGHT + ROW_GAP;
            }
        }

        if (!readOnly && tails.length > 0) {
            const id = `add:${parentId ?? 'root'}:${branch ?? ''}`;
            const at = { parentId, branch, index: list.length };

            connect(tails, id, undefined, depth);
            nodes.push({
                id,
                type: 'add',
                position: { x: centre - ADD_SIZE / 2, y },
                width: ADD_SIZE,
                height: ADD_SIZE,
                data: { at, depth },
            });

            return { tails: [{ id }], bottom: y + ADD_SIZE };
        }

        return { tails, bottom: y - ROW_GAP };
    };

    nodes.push({
        id: 'trigger',
        type: 'trigger',
        position: { x: -NODE_WIDTH / 2, y: 0 },
        width: NODE_WIDTH,
        height: NODE_HEIGHT,
        data: trigger,
    });
    sequence(
        steps,
        0,
        NODE_HEIGHT + ROW_GAP,
        [{ id: 'trigger' }],
        null,
        null,
        0,
    );

    return { nodes, edges };
}

/**
 * How wide a run of steps is once its conditions spread their branches out.
 */
function widthOf(list: AutomationStep[]): number {
    return Math.max(
        NODE_WIDTH,
        ...list.map((step) =>
            step.type === 'condition'
                ? widthOf(step.yes ?? []) + COLUMN_GAP + widthOf(step.no ?? [])
                : NODE_WIDTH,
        ),
    );
}

function NodeCard({
    kind,
    title,
    summary,
    selected,
    onSelect,
    children,
    aside,
}: {
    kind: StepType | 'trigger';
    title: string;
    summary: string;
    selected: boolean;
    onSelect: () => void;
    children?: React.ReactNode;
    aside?: React.ReactNode;
}) {
    const Icon = kinds[kind].icon;

    return (
        <div
            className={cn(
                'group relative rounded-xl border bg-card shadow-xs transition-[border-color,box-shadow]',
                selected
                    ? 'border-foreground/40 ring-3 ring-foreground/10'
                    : 'hover:border-foreground/25',
            )}
            style={{ width: NODE_WIDTH, height: NODE_HEIGHT }}
        >
            <button
                type="button"
                onClick={onSelect}
                className="flex h-full w-full cursor-pointer items-center gap-3 rounded-xl px-3.5 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                <span
                    className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-lg',
                        kinds[kind].tone,
                    )}
                >
                    <Icon className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block text-13 font-semibold">{title}</span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {summary}
                    </span>
                    {children}
                </span>
            </button>
            {aside}
        </div>
    );
}

function TriggerNode({ data }: NodeProps<Node<TriggerNodeData, 'trigger'>>) {
    const { selectedId, onSelect } = useActions();

    return (
        <>
            <NodeCard
                kind="trigger"
                title={data.title}
                summary={data.summary}
                selected={selectedId === 'trigger'}
                onSelect={() => onSelect('trigger')}
            />
            <Handle type="source" position={Position.Bottom} />
        </>
    );
}

function StepNode({ data }: NodeProps<Node<StepNodeData, 'step'>>) {
    const { readOnly, selectedId, stats, onSelect, onRemove } = useActions();
    const { step } = data;
    const counts = stats[step.id];
    const statsLine = [
        counts?.waiting ? `${counts.waiting} here now` : null,
        counts?.sent !== undefined ? `${counts.sent} sent` : null,
        counts?.opened ? `${counts.opened} opened` : null,
        counts?.clicked ? `${counts.clicked} clicked` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Handle type="target" position={Position.Top} />
            <NodeCard
                kind={step.type}
                title={stepLabels[step.type]}
                summary={summarize(step)}
                selected={selectedId === step.id}
                onSelect={() => onSelect(step.id)}
                aside={
                    !readOnly && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Remove ${stepLabels[step.type].toLowerCase()}`}
                            className="absolute top-2 right-2 size-7 text-muted-foreground opacity-100 group-hover:opacity-100 focus-visible:opacity-100 pointer-fine:opacity-0"
                            onClick={() => onRemove(step.id)}
                        >
                            <Trash2 className="size-3.5" />
                        </Button>
                    )
                }
            >
                {statsLine && (
                    <span className="mt-0.5 block truncate text-xs text-muted-foreground/80 tabular-nums">
                        {statsLine}
                    </span>
                )}
            </NodeCard>

            {step.type === 'condition' ? (
                <>
                    <BranchHandle id="yes" label="Yes" left="25%" />
                    <BranchHandle id="no" label="No" left="75%" />
                </>
            ) : (
                step.type !== 'exit' && (
                    <Handle type="source" position={Position.Bottom} />
                )
            )}
        </>
    );
}

function BranchHandle({
    id,
    label,
    left,
}: {
    id: string;
    label: string;
    left: string;
}) {
    return (
        <>
            <Handle
                type="source"
                id={id}
                position={Position.Bottom}
                style={{ left }}
            />
            <span
                className="pointer-events-none absolute -bottom-6 -translate-x-1/2 rounded-md border bg-card px-1.5 py-0.5 text-xs font-medium text-muted-foreground"
                style={{ left }}
            >
                {label}
            </span>
        </>
    );
}

function AddNode({ data }: NodeProps<Node<AddNodeData, 'add'>>) {
    return (
        <>
            <Handle type="target" position={Position.Top} />
            <AddStepMenu at={data.at} depth={data.depth} size={ADD_SIZE} />
            <Handle type="source" position={Position.Bottom} />
        </>
    );
}

function InsertEdge({
    id,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    data,
    style,
}: EdgeProps<FlowEdge>) {
    const [path, labelX, labelY] = getBezierPath({
        sourceX,
        sourceY,
        targetX,
        targetY,
        sourcePosition,
        targetPosition,
    });

    return (
        <>
            <BaseEdge id={id} path={path} style={style} />
            {data?.at && (
                <EdgeLabelRenderer>
                    <div
                        className="nodrag nopan pointer-events-auto absolute"
                        style={{
                            transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
                        }}
                    >
                        <AddStepMenu
                            at={data.at}
                            depth={data.depth}
                            size={22}
                        />
                    </div>
                </EdgeLabelRenderer>
            )}
        </>
    );
}

function AddStepMenu({
    at,
    depth,
    size,
}: {
    at: InsertPoint;
    depth: number;
    size: number;
}) {
    const { onInsert } = useActions();
    const types = (Object.keys(stepLabels) as StepType[]).filter(
        (type) => depth < MAX_DEPTH || type !== 'condition',
    );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Add a step"
                    className="flex items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs transition-colors outline-none hover:border-foreground/30 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    style={{ width: size, height: size }}
                >
                    <Plus className={size > 24 ? 'size-4' : 'size-3'} />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="center" className="w-52">
                {types.map((type) => {
                    const Icon = kinds[type].icon;

                    return (
                        <DropdownMenuItem
                            key={type}
                            onSelect={() => onInsert(at, type)}
                        >
                            <span
                                className={cn(
                                    'flex size-6 items-center justify-center rounded-md',
                                    kinds[type].tone,
                                )}
                            >
                                <Icon className="size-3.5" />
                            </span>
                            {stepLabels[type]}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

const nodeTypes = { step: StepNode, trigger: TriggerNode, add: AddNode };
const edgeTypes = { insert: InsertEdge };
