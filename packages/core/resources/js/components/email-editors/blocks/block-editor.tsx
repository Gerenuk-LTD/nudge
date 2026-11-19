import {
    closestCenter,
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    pointerWithin,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    CollisionDetection,
    DragEndEvent,
    DragOverEvent,
    DragStartEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    ArrowDown,
    ArrowUp,
    CodeXml,
    Columns2,
    Copy,
    GripVertical,
    Heading,
    ImageIcon,
    Minus,
    Monitor,
    MousePointerClick,
    MoveVertical,
    PanelBottom,
    Pilcrow,
    Smartphone,
    Trash2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    createContext,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { fillVariables } from '@nudge/lib/merge-tags';
import type { EmailEditorProps } from '../types';
import { Inspector } from './inspector';
import {
    asDocument,
    cloneBlock,
    COLUMN_LAYOUTS,
    createBlock,
    defaultDocument,
} from './model';
import type { Block, BlockType, EmailDocument } from './model';
import {
    buttonStyle,
    cellStyle,
    COLUMN_GAP,
    columnWidth,
    contentWidth,
    FOOTER_TAGS,
    footerStyle,
    headingStyle,
    imageWidth,
    renderEmail,
    textStyle,
} from './render';
import { RichText } from './rich-text';
import {
    columnContainer,
    findBlock,
    getContainer,
    insertBlock,
    locate,
    moveBlock,
    removeBlock,
    ROOT,
    updateBlock,
} from './tree';

export const blockKinds: Record<
    BlockType,
    { label: string; icon: LucideIcon }
> = {
    heading: { label: 'Heading', icon: Heading },
    text: { label: 'Text', icon: Pilcrow },
    button: { label: 'Button', icon: MousePointerClick },
    image: { label: 'Image', icon: ImageIcon },
    columns: { label: 'Columns', icon: Columns2 },
    html: { label: 'HTML', icon: CodeXml },
    divider: { label: 'Divider', icon: Minus },
    spacer: { label: 'Spacer', icon: MoveVertical },
    footer: { label: 'Footer', icon: PanelBottom },
};

const PALETTE_PREFIX = 'new:';
const ZONE_PREFIX = 'zone:';

type Target = { container: string; index: number };

type Canvas = {
    document: EmailDocument;
    selectedId: string | null;
    indicator: Target | null;
    dragging: boolean;
    mergeTags: EmailEditorProps['mergeTags'];
    select: (id: string) => void;
    change: (id: string, patch: Partial<Block>) => void;
    move: (id: string, by: number) => void;
    duplicate: (id: string) => void;
    remove: (id: string) => void;
};

const CanvasContext = createContext<Canvas | null>(null);

function useCanvas(): Canvas {
    const canvas = useContext(CanvasContext);

    if (!canvas) {
        throw new Error('Blocks must be rendered inside the block editor.');
    }

    return canvas;
}

/**
 * A drag-and-drop email builder: drag blocks from the left onto the email or into columns, edit text in place, and
 * set each block's details on the right. It keeps its document in `state` and hands back the finished email HTML on
 * every change.
 */
export function BlockEditor({
    html,
    state,
    mergeTags,
    onChange,
    syncOnMount = true,
    previewValues,
}: EmailEditorProps) {
    const [document, setDocument] = useState<EmailDocument>(
        () => asDocument(state) ?? defaultDocument(),
    );
    const latest = useRef(document);
    const latestOnChange = useRef(onChange);
    latestOnChange.current = onChange;
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [mode, setMode] = useState<'edit' | 'desktop' | 'mobile'>('edit');
    const [dragging, setDragging] = useState<string | null>(null);
    const [target, setTarget] = useState<Target | null>(null);

    const commit = (next: EmailDocument) => {
        latest.current = next;
        setDocument(next);
        latestOnChange.current(renderEmail(next), next);
    };

    const changeBlocks = (change: (blocks: Block[]) => Block[]) =>
        commit({ ...latest.current, blocks: change(latest.current.blocks) });

    // Start from HTML that matches the document, so emails made from a starter have content before the first edit.
    useEffect(() => {
        const rendered = renderEmail(latest.current);

        if (syncOnMount && (rendered !== html || asDocument(state) === null)) {
            latestOnChange.current(rendered, latest.current, true);
        }
        // Only on first load; later changes go through commit.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const add = (type: BlockType, at: Target) => {
        const block = createBlock(type);

        changeBlocks((blocks) =>
            insertBlock(blocks, block, at.container, at.index),
        );
        setSelectedId(block.id);
    };

    const canvas: Canvas = {
        document,
        selectedId,
        indicator: target,
        dragging: dragging !== null,
        mergeTags,
        select: setSelectedId,
        change: (id, patch) =>
            changeBlocks((blocks) =>
                updateBlock(
                    blocks,
                    id,
                    (block) => ({ ...block, ...patch }) as Block,
                ),
            ),
        move: (id, by) =>
            changeBlocks((blocks) => {
                const location = locate(blocks, id);

                return location
                    ? moveBlock(
                          blocks,
                          id,
                          location.container,
                          location.index + by,
                      )
                    : blocks;
            }),
        duplicate: (id) => {
            const block = findBlock(latest.current.blocks, id);
            const location = locate(latest.current.blocks, id);

            if (!block || !location) {
                return;
            }

            const copy = cloneBlock(block);
            changeBlocks((blocks) =>
                insertBlock(
                    blocks,
                    copy,
                    location.container,
                    location.index + 1,
                ),
            );
            setSelectedId(copy.id);
        },
        remove: (id) => {
            changeBlocks((blocks) => removeBlock(blocks, id));
            setSelectedId(null);
        },
    };

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const columnIds = new Set(
        document.blocks
            .filter((block) => block.type === 'columns')
            .map((block) => block.id),
    );

    // Prefer what is under the pointer, and the innermost thing at that: a block in a column over the columns around it.
    const collisionDetection: CollisionDetection = (args) => {
        const hits = pointerWithin(args);

        if (hits.length === 0) {
            return closestCenter(args);
        }

        const inner = hits.filter((hit) => !columnIds.has(String(hit.id)));

        return inner.length > 0 ? inner : hits;
    };

    /** Where a drop over `overId` would land: before that block, or at the end of a drop zone's container. */
    const targetOf = (overId: string): Target | null => {
        if (overId.startsWith(ZONE_PREFIX)) {
            const container = overId.slice(ZONE_PREFIX.length);

            return {
                container,
                index: getContainer(latest.current.blocks, container).length,
            };
        }

        return locate(latest.current.blocks, overId);
    };

    const onDragStart = ({ active }: DragStartEvent) =>
        setDragging(String(active.id));

    const onDragOver = ({ active, over }: DragOverEvent) => {
        const next = over ? targetOf(String(over.id)) : null;
        const from = locate(latest.current.blocks, String(active.id));

        // Moves within one list already show by the blocks sliding apart; mark only drops into a new place.
        setTarget(
            next && from?.container === next.container && !overIsZone(over?.id)
                ? null
                : next,
        );
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setDragging(null);
        setTarget(null);

        const activeId = String(active.id);
        const to = over ? targetOf(String(over.id)) : null;

        if (!to || String(over?.id) === activeId) {
            return;
        }

        if (activeId.startsWith(PALETTE_PREFIX)) {
            add(activeId.slice(PALETTE_PREFIX.length) as BlockType, to);

            return;
        }

        changeBlocks((blocks) =>
            moveBlock(blocks, activeId, to.container, to.index),
        );
    };

    const selected = findBlock(document.blocks, selectedId);
    const selectedLocation = selectedId
        ? locate(document.blocks, selectedId)
        : null;
    const addAt: Target = selectedLocation
        ? {
              container: selectedLocation.container,
              index: selectedLocation.index + 1,
          }
        : { container: ROOT, index: document.blocks.length };
    const paletteDrag = dragging?.startsWith(PALETTE_PREFIX)
        ? (dragging.slice(PALETTE_PREFIX.length) as BlockType)
        : null;
    const rendered = useMemo(
        () =>
            mode === 'edit'
                ? ''
                : fillVariables(renderEmail(document), previewValues ?? {}, {
                      escapeHtml: true,
                  }),
        [mode, document, previewValues],
    );

    return (
        <div className="@container isolate overflow-hidden rounded-xl border bg-card">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
                <div
                    role="tablist"
                    aria-label="View"
                    className="flex items-center gap-1 rounded-lg bg-muted p-0.5"
                >
                    <ModeButton
                        active={mode === 'edit'}
                        onClick={() => setMode('edit')}
                    >
                        Edit
                    </ModeButton>
                    <ModeButton
                        active={mode === 'desktop'}
                        onClick={() => setMode('desktop')}
                    >
                        <Monitor className="size-3.5" />
                        Desktop
                    </ModeButton>
                    <ModeButton
                        active={mode === 'mobile'}
                        onClick={() => setMode('mobile')}
                    >
                        <Smartphone className="size-3.5" />
                        Mobile
                    </ModeButton>
                </div>
                <p className="text-xs text-muted-foreground">
                    {mode === 'edit'
                        ? 'Drag blocks onto the email, or click one to add it below the selected block.'
                        : 'How it looks in an inbox. Fields show as tags until sent.'}
                </p>
            </div>

            {mode === 'edit' ? (
                <DndContext
                    sensors={sensors}
                    collisionDetection={collisionDetection}
                    onDragStart={onDragStart}
                    onDragOver={onDragOver}
                    onDragEnd={onDragEnd}
                    onDragCancel={() => {
                        setDragging(null);
                        setTarget(null);
                    }}
                >
                    <CanvasContext.Provider value={canvas}>
                        <div className="grid min-h-[40rem] @4xl:grid-cols-[11rem_minmax(0,1fr)_17rem]">
                            <aside className="space-y-2 border-b p-3 @4xl:border-r @4xl:border-b-0">
                                <p className="px-1 text-xs font-medium text-muted-foreground">
                                    Blocks
                                </p>
                                <div className="grid grid-cols-4 gap-1.5 @xl:grid-cols-9 @4xl:grid-cols-2">
                                    {(
                                        Object.keys(blockKinds) as BlockType[]
                                    ).map((type) => (
                                        <PaletteItem
                                            key={type}
                                            type={type}
                                            onAdd={() => add(type, addAt)}
                                        />
                                    ))}
                                </div>
                            </aside>

                            <div
                                className="overflow-x-auto px-4 py-8"
                                style={{
                                    background: document.settings.backdrop,
                                }}
                                onMouseDown={(event) => {
                                    if (event.target === event.currentTarget) {
                                        setSelectedId(null);
                                    }
                                }}
                            >
                                <div
                                    className="mx-auto rounded-lg py-4 shadow-xs"
                                    style={{
                                        width: document.settings.width,
                                        background: document.settings.canvas,
                                    }}
                                >
                                    <BlockList
                                        container={ROOT}
                                        blocks={document.blocks}
                                        available={contentWidth(
                                            document.settings,
                                        )}
                                    />
                                </div>
                            </div>

                            <aside className="border-t p-4 @4xl:border-t-0 @4xl:border-l">
                                <Inspector
                                    document={document}
                                    block={selected}
                                    onBlockChange={(patch) =>
                                        selected &&
                                        canvas.change(selected.id, patch)
                                    }
                                    onSettingsChange={(patch) =>
                                        commit({
                                            ...latest.current,
                                            settings: {
                                                ...latest.current.settings,
                                                ...patch,
                                            },
                                        })
                                    }
                                    onDeselect={() => setSelectedId(null)}
                                />
                            </aside>
                        </div>
                    </CanvasContext.Provider>

                    <DragOverlay dropAnimation={null}>
                        {paletteDrag && (
                            <div className="flex items-center gap-2 rounded-lg border bg-popover px-3 py-2 text-13 font-medium shadow-lg">
                                {(() => {
                                    const Icon = blockKinds[paletteDrag].icon;

                                    return <Icon className="size-4" />;
                                })()}
                                {blockKinds[paletteDrag].label}
                            </div>
                        )}
                    </DragOverlay>
                </DndContext>
            ) : (
                <div
                    className="flex justify-center bg-muted/40 p-6"
                    style={{ minHeight: '40rem' }}
                >
                    <iframe
                        title="Email preview"
                        sandbox=""
                        srcDoc={rendered}
                        className="h-[40rem] max-w-full rounded-lg border bg-white shadow-sm transition-[width]"
                        style={{ width: mode === 'mobile' ? 375 : '100%' }}
                    />
                </div>
            )}
        </div>
    );
}

function overIsZone(id: unknown): boolean {
    return typeof id === 'string' && id.startsWith(ZONE_PREFIX);
}

function ModeButton({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            role="tab"
            aria-selected={active}
            onClick={onClick}
            className={cn(
                'flex h-7 items-center gap-1.5 rounded-md px-2.5 text-13 font-medium text-muted-foreground transition-colors',
                active && 'bg-background text-foreground shadow-xs',
            )}
        >
            {children}
        </button>
    );
}

function PaletteItem({ type, onAdd }: { type: BlockType; onAdd: () => void }) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: `${PALETTE_PREFIX}${type}`,
    });
    const { label, icon: Icon } = blockKinds[type];

    return (
        <button
            ref={setNodeRef}
            type="button"
            {...attributes}
            {...listeners}
            onClick={onAdd}
            aria-label={`Add ${label.toLowerCase()} block`}
            className={cn(
                'flex cursor-grab flex-col items-center gap-1.5 rounded-lg border bg-background px-2 py-3 text-xs font-medium text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground active:cursor-grabbing',
                isDragging && 'opacity-50',
            )}
        >
            <Icon className="size-4" />
            {label}
        </button>
    );
}

/**
 * The blocks of one container, in drag order, with a zone after them to drop at the end.
 */
function BlockList({
    container,
    blocks,
    available,
    column,
}: {
    container: string;
    blocks: Block[];
    available: number;
    /** The column's position when the list is inside a columns block. */
    column?: number;
}) {
    const nested = column !== undefined;

    return (
        <SortableContext
            items={blocks.map((block) => block.id)}
            strategy={verticalListSortingStrategy}
        >
            {blocks.map((block, index) => (
                <SortableBlock
                    key={block.id}
                    block={block}
                    container={container}
                    index={index}
                    last={index === blocks.length - 1}
                    nested={nested}
                >
                    <BlockView
                        block={block}
                        available={available}
                        nested={nested}
                        toolbarEnd={nested && column > 0}
                    />
                </SortableBlock>
            ))}
            <DropZone
                container={container}
                count={blocks.length}
                nested={nested}
            />
        </SortableContext>
    );
}

function DropZone({
    container,
    count,
    nested,
}: {
    container: string;
    count: number;
    nested: boolean;
}) {
    const { indicator, dragging } = useCanvas();
    const { setNodeRef } = useDroppable({ id: `${ZONE_PREFIX}${container}` });
    const empty = count === 0;
    const highlighted =
        dragging &&
        indicator?.container === container &&
        indicator.index === count;

    return (
        <div
            ref={setNodeRef}
            className={cn(
                'flex items-center justify-center rounded-md border border-dashed text-xs text-zinc-400 transition-colors',
                nested ? 'mx-0' : 'mx-8',
                empty
                    ? nested
                        ? 'my-2 h-20'
                        : 'h-32'
                    : dragging
                      ? 'mt-1 h-8'
                      : 'h-2 border-transparent',
                highlighted
                    ? 'border-sky-500 bg-sky-50 text-sky-600'
                    : empty
                      ? 'border-zinc-300'
                      : dragging
                        ? 'border-zinc-200'
                        : 'border-transparent',
            )}
        >
            {(empty || dragging) && 'Drop a block here'}
        </div>
    );
}

function SortableBlock({
    block,
    container,
    index,
    last,
    nested,
    children,
}: {
    block: Block;
    container: string;
    index: number;
    last: boolean;
    nested: boolean;
    children: ReactNode;
}) {
    const canvas = useCanvas();
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: block.id });
    const label = blockKinds[block.type].label;
    const selected = canvas.selectedId === block.id;
    const dropBefore =
        canvas.indicator?.container === container &&
        canvas.indicator.index === index;

    return (
        <div
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition,
            }}
            onMouseDown={(event) => {
                // A block inside columns is selected rather than the columns around it.
                event.stopPropagation();
                canvas.select(block.id);
            }}
            onFocus={(event) => {
                event.stopPropagation();
                canvas.select(block.id);
            }}
            className={cn(
                'group/block relative outline-2 -outline-offset-2 outline-transparent transition-[outline-color]',
                selected
                    ? 'outline-sky-500'
                    : 'hover:outline-sky-500/40 hover:outline-dashed',
                isDragging && 'z-30 opacity-60',
            )}
        >
            {dropBefore && (
                <span
                    className={cn(
                        'absolute -top-px z-20 h-0.5 rounded-full bg-sky-500',
                        nested ? 'inset-x-0' : 'inset-x-6',
                    )}
                />
            )}
            <button
                ref={setActivatorNodeRef}
                type="button"
                {...attributes}
                {...listeners}
                aria-label={`Drag ${label.toLowerCase()} block`}
                className={cn(
                    'absolute z-10 flex size-6 cursor-grab items-center justify-center rounded text-zinc-400 opacity-0 transition-opacity group-hover/block:opacity-100 hover:bg-zinc-100 hover:text-zinc-700 focus-visible:opacity-100 active:cursor-grabbing',
                    nested
                        ? 'top-1 -left-3 border bg-white shadow-xs'
                        : 'top-1/2 left-1.5 -translate-y-1/2',
                    selected && 'opacity-100',
                )}
            >
                <GripVertical className="size-4" />
            </button>
            {selected && (
                <div className="absolute -top-3.5 right-2 z-20 flex items-center gap-0.5 rounded-md border bg-popover p-0.5 font-sans text-popover-foreground shadow-sm">
                    <span className="px-1.5 text-xs font-medium text-muted-foreground">
                        {label}
                    </span>
                    <BlockAction
                        label="Move up"
                        disabled={index === 0}
                        onClick={() => canvas.move(block.id, -1)}
                    >
                        <ArrowUp />
                    </BlockAction>
                    <BlockAction
                        label="Move down"
                        disabled={last}
                        onClick={() => canvas.move(block.id, 1)}
                    >
                        <ArrowDown />
                    </BlockAction>
                    <BlockAction
                        label="Duplicate"
                        onClick={() => canvas.duplicate(block.id)}
                    >
                        <Copy />
                    </BlockAction>
                    <BlockAction
                        label="Delete"
                        destructive
                        onClick={() => canvas.remove(block.id)}
                    >
                        <Trash2 />
                    </BlockAction>
                </div>
            )}
            {children}
        </div>
    );
}

function BlockAction({
    label,
    disabled = false,
    destructive = false,
    onClick,
    children,
}: {
    label: string;
    disabled?: boolean;
    destructive?: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Button
            type="button"
            variant={destructive ? 'destructive-ghost' : 'ghost'}
            size="icon"
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={onClick}
            className="size-6 [&_svg:not([class*='size-'])]:size-3.5"
        >
            {children}
        </Button>
    );
}

/**
 * A block as it will look in the email, with its text editable in place.
 */
function BlockView({
    block,
    available,
    nested,
    toolbarEnd,
}: {
    block: Block;
    /** The width in pixels the block has to fill. */
    available: number;
    nested: boolean;
    toolbarEnd: boolean;
}) {
    const { document, selectedId, mergeTags, change } = useCanvas();
    const { settings } = document;
    const cell = cellStyle(block, nested);
    const selected = selectedId === block.id;
    const onChange = (patch: Partial<Block>) => change(block.id, patch);

    switch (block.type) {
        case 'columns': {
            const shares = COLUMN_LAYOUTS[block.layout];

            return (
                <div style={cell}>
                    <div className="flex">
                        {block.columns.map((column, index) => (
                            <div
                                key={index}
                                className="min-w-0"
                                style={{
                                    width: `${shares[index]}%`,
                                    padding: `0 ${COLUMN_GAP / 2}px`,
                                }}
                            >
                                <BlockList
                                    container={columnContainer(block.id, index)}
                                    blocks={column}
                                    available={columnWidth(
                                        settings,
                                        shares[index],
                                    )}
                                    column={index}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            );
        }
        case 'heading':
            return (
                <div style={cell}>
                    <RichText
                        key={`${block.id}-${block.level}`}
                        html={block.html}
                        singleLine
                        selected={selected}
                        toolbarEnd={toolbarEnd}
                        mergeTags={mergeTags}
                        onChange={(html) => onChange({ html })}
                        style={{
                            ...headingStyle(block.level, settings),
                            ['--email-link' as string]: settings.link,
                        }}
                    />
                </div>
            );
        case 'text':
            return (
                <div style={cell}>
                    <RichText
                        html={block.html}
                        selected={selected}
                        toolbarEnd={toolbarEnd}
                        mergeTags={mergeTags}
                        onChange={(html) => onChange({ html })}
                        style={{
                            ...textStyle(settings),
                            ['--email-link' as string]: settings.link,
                        }}
                    />
                </div>
            );
        case 'footer':
            return (
                <div style={cell}>
                    <RichText
                        html={block.html}
                        selected={selected}
                        toolbarEnd={toolbarEnd}
                        mergeTags={mergeTags}
                        onChange={(html) => onChange({ html })}
                        style={{
                            ...footerStyle(settings),
                            ['--email-link' as string]: '#71717a',
                        }}
                    />
                    <p
                        style={{
                            ...footerStyle(settings),
                            margin: '12px 0 0',
                            ['--email-link' as string]: '#71717a',
                        }}
                        className="email-rich"
                        dangerouslySetInnerHTML={{ __html: FOOTER_TAGS }}
                    />
                </div>
            );
        case 'button':
            return (
                <div style={cell}>
                    <span style={buttonStyle(block, settings)}>
                        {block.label || 'Button'}
                    </span>
                </div>
            );
        case 'image':
            return (
                <div style={cell}>
                    {block.src ? (
                        <img
                            src={block.src}
                            alt={block.alt}
                            style={{
                                display: 'inline-block',
                                width: '100%',
                                maxWidth: imageWidth(block, available),
                                height: 'auto',
                            }}
                        />
                    ) : (
                        <div className="flex h-36 flex-col items-center justify-center gap-2 rounded-md border border-dashed border-zinc-300 bg-zinc-50 px-3 text-center text-xs text-zinc-500">
                            <ImageIcon className="size-5" />
                            Upload an image on the right
                        </div>
                    )}
                </div>
            );
        case 'html':
            return (
                <div style={cell}>
                    <HtmlPreview html={block.html} />
                </div>
            );
        case 'divider':
            return (
                <div style={cell}>
                    <div style={{ height: 1, background: '#e4e4e7' }} />
                </div>
            );
        case 'spacer':
            return (
                <div
                    style={{ height: block.height }}
                    className={cn(selected && 'bg-sky-500/5')}
                />
            );
    }
}

/**
 * Hand-written HTML shown as it renders, in a frame that runs no scripts and grows to fit its content. Clicks pass
 * through to the block so it can be selected and dragged.
 */
function HtmlPreview({ html }: { html: string }) {
    const frame = useRef<HTMLIFrameElement>(null);
    const [height, setHeight] = useState(48);
    const observer = useRef<ResizeObserver | null>(null);

    // Measure the whole document, margins included, and again whenever its layout settles or changes.
    const watch = () => {
        const root = frame.current?.contentDocument?.documentElement;

        if (!root) {
            return;
        }

        const measure = () => setHeight(Math.max(24, root.scrollHeight));
        observer.current?.disconnect();
        observer.current = new ResizeObserver(measure);
        observer.current.observe(root);
        measure();
    };

    useEffect(() => {
        // The frame can finish loading before React is listening, so check once it is mounted too.
        const loaded = frame.current?.contentDocument;

        if (
            loaded?.readyState === 'complete' &&
            loaded.URL === 'about:srcdoc'
        ) {
            watch();
        }

        return () => observer.current?.disconnect();
        // Only on mount; later loads come through onLoad.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <iframe
            ref={frame}
            title="HTML block"
            // Same origin only so its height can be read; without allow-scripts nothing in it runs.
            sandbox="allow-same-origin"
            srcDoc={`<!DOCTYPE html><html><body style="margin:0;overflow:hidden">${html}<div style="clear:both"></div></body></html>`}
            onLoad={watch}
            scrolling="no"
            className="pointer-events-none block w-full border-0"
            style={{ height }}
        />
    );
}
