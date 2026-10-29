import { AlignCenter, AlignLeft, AlignRight, ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { ImagePicker } from './image-picker';
import { COLUMN_LAYOUTS, FONTS, withLayout } from './model';
import type {
    Align,
    Block,
    ColumnLayout,
    EmailDocument,
    EmailSettings,
} from './model';

/**
 * The details of the selected block, or of the whole email when nothing is selected.
 */
export function Inspector({
    document,
    block,
    onBlockChange,
    onSettingsChange,
    onDeselect,
}: {
    document: EmailDocument;
    block: Block | null;
    onBlockChange: (patch: Partial<Block>) => void;
    onSettingsChange: (patch: Partial<EmailSettings>) => void;
    onDeselect: () => void;
}) {
    if (block === null) {
        return (
            <Panel
                title="Email"
                hint="Select a block on the email to change it."
            >
                <Field label="Font">
                    <NativeSelect
                        value={document.settings.font}
                        onChange={(event) =>
                            onSettingsChange({ font: event.target.value })
                        }
                    >
                        {FONTS.map((font) => (
                            <option key={font.value} value={font.value}>
                                {font.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <ColorField
                    label="Text"
                    value={document.settings.text}
                    onChange={(text) => onSettingsChange({ text })}
                />
                <ColorField
                    label="Links"
                    value={document.settings.link}
                    onChange={(link) => onSettingsChange({ link })}
                />
                <ColorField
                    label="Email background"
                    value={document.settings.canvas}
                    onChange={(canvas) => onSettingsChange({ canvas })}
                />
                <ColorField
                    label="Page background"
                    value={document.settings.backdrop}
                    onChange={(backdrop) => onSettingsChange({ backdrop })}
                />
                <RangeField
                    label="Width"
                    value={document.settings.width}
                    min={480}
                    max={720}
                    step={10}
                    unit="px"
                    onChange={(width) => onSettingsChange({ width })}
                />
            </Panel>
        );
    }

    const align = 'align' in block && (
        <Field label="Alignment">
            <AlignControl
                value={block.align}
                onChange={(value) => onBlockChange({ align: value })}
            />
        </Field>
    );

    return (
        <Panel
            title={titles[block.type]}
            back={onDeselect}
            hint={hints[block.type]}
        >
            {block.type === 'heading' && (
                <Field label="Size">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={String(block.level)}
                        onValueChange={(value) =>
                            value &&
                            onBlockChange({
                                level: Number(value) as 1 | 2 | 3,
                            })
                        }
                    >
                        <ToggleGroupItem value="1">Large</ToggleGroupItem>
                        <ToggleGroupItem value="2">Medium</ToggleGroupItem>
                        <ToggleGroupItem value="3">Small</ToggleGroupItem>
                    </ToggleGroup>
                </Field>
            )}

            {block.type === 'button' && (
                <>
                    <Field label="Label" htmlFor="block-label">
                        <Input
                            id="block-label"
                            value={block.label}
                            onChange={(event) =>
                                onBlockChange({ label: event.target.value })
                            }
                        />
                    </Field>
                    <Field label="Link" htmlFor="block-url">
                        <Input
                            id="block-url"
                            type="url"
                            value={block.url}
                            placeholder="https://"
                            onChange={(event) =>
                                onBlockChange({ url: event.target.value })
                            }
                        />
                    </Field>
                    <ColorField
                        label="Button colour"
                        value={block.background}
                        onChange={(background) => onBlockChange({ background })}
                    />
                    <ColorField
                        label="Label colour"
                        value={block.color}
                        onChange={(color) => onBlockChange({ color })}
                    />
                </>
            )}

            {block.type === 'image' && (
                <>
                    <ImagePicker
                        value={block.src}
                        onPick={(asset) => onBlockChange({ src: asset.url })}
                    />
                    <details className="group text-13">
                        <summary className="cursor-pointer text-muted-foreground hover:text-foreground">
                            Or use an image address
                        </summary>
                        <Input
                            aria-label="Image address"
                            type="url"
                            value={block.src}
                            placeholder="https://…/photo.jpg"
                            onChange={(event) =>
                                onBlockChange({ src: event.target.value })
                            }
                            className="mt-2"
                        />
                    </details>
                    <Field
                        label="Description"
                        htmlFor="block-alt"
                        hint="Shown when images are off, and read aloud by screen readers."
                    >
                        <Input
                            id="block-alt"
                            value={block.alt}
                            onChange={(event) =>
                                onBlockChange({ alt: event.target.value })
                            }
                        />
                    </Field>
                    <Field label="Link (optional)" htmlFor="block-link">
                        <Input
                            id="block-link"
                            type="url"
                            value={block.url}
                            placeholder="https://"
                            onChange={(event) =>
                                onBlockChange({ url: event.target.value })
                            }
                        />
                    </Field>
                    <RangeField
                        label="Width"
                        value={block.width}
                        min={20}
                        max={100}
                        step={5}
                        unit="%"
                        onChange={(width) => onBlockChange({ width })}
                    />
                </>
            )}

            {block.type === 'columns' && (
                <Field label="Layout">
                    <div className="grid grid-cols-2 gap-2">
                        {(Object.keys(COLUMN_LAYOUTS) as ColumnLayout[]).map(
                            (layout) => (
                                <button
                                    key={layout}
                                    type="button"
                                    aria-label={layoutLabels[layout]}
                                    aria-pressed={block.layout === layout}
                                    title={layoutLabels[layout]}
                                    onClick={() =>
                                        onBlockChange(withLayout(block, layout))
                                    }
                                    className={cn(
                                        'flex h-10 gap-1 rounded-md border bg-background p-1.5 transition-colors hover:border-foreground/30',
                                        block.layout === layout &&
                                            'border-foreground ring-1 ring-foreground',
                                    )}
                                >
                                    {COLUMN_LAYOUTS[layout].map(
                                        (share, index) => (
                                            <span
                                                key={index}
                                                className="rounded-sm bg-muted-foreground/25"
                                                style={{ flexGrow: share }}
                                            />
                                        ),
                                    )}
                                </button>
                            ),
                        )}
                    </div>
                </Field>
            )}

            {block.type === 'html' && (
                <Field label="HTML" htmlFor="block-html">
                    <textarea
                        id="block-html"
                        value={block.html}
                        spellCheck={false}
                        onChange={(event) =>
                            onBlockChange({ html: event.target.value })
                        }
                        className="min-h-72 w-full rounded-md border border-input bg-card p-2.5 font-mono text-xs leading-relaxed shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </Field>
            )}

            {block.type === 'spacer' && (
                <RangeField
                    label="Height"
                    value={block.height}
                    min={8}
                    max={96}
                    step={4}
                    unit="px"
                    onChange={(height) => onBlockChange({ height })}
                />
            )}

            {align}
        </Panel>
    );
}

const layoutLabels: Record<ColumnLayout, string> = {
    '1-1': 'Two equal columns',
    '1-2': 'Narrow then wide',
    '2-1': 'Wide then narrow',
    '1-1-1': 'Three equal columns',
};

const titles: Record<Block['type'], string> = {
    columns: 'Columns',
    html: 'HTML',
    heading: 'Heading',
    text: 'Text',
    button: 'Button',
    image: 'Image',
    divider: 'Divider',
    spacer: 'Spacer',
    footer: 'Footer',
};

const hints: Record<Block['type'], string> = {
    columns:
        'Drag blocks into each column. On phones the columns stack, left one first.',
    html: 'Your own HTML, sent exactly as written. Fields such as {{ first_name }} work here too.',
    heading: 'Click the heading on the email to change its words.',
    text: 'Click the text on the email to edit it. Select words to make them bold or a link.',
    button: 'A link people can tap easily.',
    image: 'Upload a photo or logo. Inboxes load it from your workspace, so keep it under 1 MB if you can.',
    divider: 'A thin line between sections.',
    spacer: 'Empty space between blocks.',
    footer: 'Your postal address and an unsubscribe link are always added below this text.',
};

function Panel({
    title,
    hint,
    back,
    children,
}: {
    title: string;
    hint: string;
    back?: () => void;
    children: ReactNode;
}) {
    return (
        <div className="space-y-4">
            <div className="space-y-1">
                <div className="flex items-center gap-1">
                    {back && (
                        <button
                            type="button"
                            onClick={back}
                            aria-label="Back to email settings"
                            className="-ml-1 flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <ArrowLeft className="size-3.5" />
                        </button>
                    )}
                    <h3 className="text-sm font-semibold">{title}</h3>
                </div>
                <p className="text-xs text-muted-foreground">{hint}</p>
            </div>
            {children}
        </div>
    );
}

function Field({
    label,
    htmlFor,
    hint,
    children,
}: {
    label: string;
    htmlFor?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={htmlFor} className="text-13">
                {label}
            </Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

function ColorField({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    const id = `color-${label.toLowerCase().replace(/\s+/g, '-')}`;

    return (
        <Field label={label} htmlFor={id}>
            <div className="flex items-center gap-2">
                <input
                    type="color"
                    aria-label={`${label} picker`}
                    value={/^#[0-9a-f]{6}$/i.test(value) ? value : '#000000'}
                    onChange={(event) => onChange(event.target.value)}
                    className="size-9 shrink-0 cursor-pointer rounded-md border bg-card p-1"
                />
                <Input
                    id={id}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    className="font-mono text-13"
                />
            </div>
        </Field>
    );
}

function RangeField({
    label,
    value,
    min,
    max,
    step,
    unit,
    onChange,
}: {
    label: string;
    value: number;
    min: number;
    max: number;
    step: number;
    unit: string;
    onChange: (value: number) => void;
}) {
    return (
        <Field label={label}>
            <div className="flex items-center gap-3">
                <input
                    type="range"
                    aria-label={label}
                    min={min}
                    max={max}
                    step={step}
                    value={value}
                    onChange={(event) => onChange(Number(event.target.value))}
                    className="w-full accent-foreground"
                />
                <span className="w-12 text-right text-13 text-muted-foreground tabular-nums">
                    {value}
                    {unit}
                </span>
            </div>
        </Field>
    );
}

function AlignControl({
    value,
    onChange,
}: {
    value: Align;
    onChange: (value: Align) => void;
}) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={value}
            onValueChange={(next) => next && onChange(next as Align)}
        >
            <ToggleGroupItem value="left" aria-label="Align left">
                <AlignLeft />
            </ToggleGroupItem>
            <ToggleGroupItem value="center" aria-label="Align centre">
                <AlignCenter />
            </ToggleGroupItem>
            <ToggleGroupItem value="right" aria-label="Align right">
                <AlignRight />
            </ToggleGroupItem>
        </ToggleGroup>
    );
}
