import { Blocks, Code } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { documentFromHtml } from './blocks/model';
import { editorFor, emailEditors } from './registry';
import type { MergeTag } from './types';

export type EmailContent = {
    editor: string;
    state: unknown;
    html: string;
};

const icons: Record<string, typeof Blocks> = { blocks: Blocks, html: Code };

/**
 * An email's content in the editor it was made with, and a switch between the drag-and-drop builder and raw HTML.
 * Switching either way keeps the email: to HTML as the builder's output, to the builder as one HTML block.
 */
export function EmailContentEditor({
    content,
    mergeTags,
    onChange,
    actions,
    previewValues,
}: {
    content: EmailContent;
    /** Extra controls on the row with the editor switch, such as template actions. */
    actions?: ReactNode;
    mergeTags: MergeTag[];
    /** Campaign variable values to show in the preview only. What the editor saves keeps the tags. */
    previewValues?: Record<string, string>;
    onChange: (content: EmailContent, initial?: boolean) => void;
}) {
    // Drag and drop is the default: every email opens in the builder unless HTML is picked on the switch.
    const [chosen, setChosen] = useState<string | null>(null);
    const editorKey = chosen ?? 'blocks';
    const Editor = editorFor(editorKey).component;
    // An email written in HTML opens in the builder as one HTML block, and is only rewritten once it is edited.
    const showingHtmlAsBlocks =
        editorKey === 'blocks' &&
        content.editor !== 'blocks' &&
        content.html.trim() !== '';
    const state = useMemo(
        () =>
            showingHtmlAsBlocks
                ? documentFromHtml(content.html)
                : editorKey === content.editor
                  ? content.state
                  : null,
        // Recomputed only when the editor in use changes; the builder keeps its own copy as it is edited.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [editorKey, showingHtmlAsBlocks],
    );

    const switchTo = (key: string) => {
        if (key === editorKey) {
            return;
        }

        setChosen(key);
        onChange({
            editor: key,
            // Into the builder, existing HTML comes along as an HTML block rather than being lost.
            state:
                key === 'blocks' && content.html.trim() !== ''
                    ? documentFromHtml(content.html)
                    : null,
            html: content.html,
        });
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div
                    role="radiogroup"
                    aria-label="Editor"
                    className="inline-flex items-center gap-1 rounded-lg bg-muted p-0.5"
                >
                    {Object.values(emailEditors).map((editor) => {
                        const Icon = icons[editor.key];
                        const active = editor.key === editorKey;

                        return (
                            <button
                                key={editor.key}
                                type="button"
                                role="radio"
                                aria-checked={active}
                                onClick={() => switchTo(editor.key)}
                                className={cn(
                                    'flex h-7 items-center gap-1.5 rounded-md px-2.5 text-13 font-medium text-muted-foreground transition-colors',
                                    active &&
                                        'bg-background text-foreground shadow-xs',
                                )}
                            >
                                {Icon && <Icon className="size-3.5" />}
                                {editor.label}
                            </button>
                        );
                    })}
                </div>
                {actions && (
                    <div className="flex flex-wrap gap-2">{actions}</div>
                )}
            </div>

            <Editor
                // Remount when switching, so each editor starts from the content it is handed.
                key={editorKey}
                html={content.html}
                state={state}
                syncOnMount={!showingHtmlAsBlocks}
                mergeTags={mergeTags}
                previewValues={previewValues}
                onChange={(html, state, initial) =>
                    onChange({ editor: editorKey, state, html }, initial)
                }
            />
        </div>
    );
}
