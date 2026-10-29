import { useRef } from 'react';
import { Button } from '@/components/ui/button';
import type { EmailEditorProps } from './types';

/**
 * A code editor with a live preview. The interim editor until the drag-and-drop builder arrives.
 */
export function HtmlEditor({ html, mergeTags, onChange }: EmailEditorProps) {
    const textarea = useRef<HTMLTextAreaElement>(null);

    const insert = (tag: string) => {
        const element = textarea.current;

        if (!element) {
            onChange(html + tag, null);

            return;
        }

        const next =
            html.slice(0, element.selectionStart) +
            tag +
            html.slice(element.selectionEnd);
        const cursor = element.selectionStart + tag.length;

        onChange(next, null);
        requestAnimationFrame(() => {
            element.focus();
            element.setSelectionRange(cursor, cursor);
        });
    };

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap gap-1">
                {mergeTags.map((tag) => (
                    <Button
                        key={tag.tag}
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7 text-xs"
                        onClick={() => insert(tag.tag)}
                    >
                        {tag.label}
                    </Button>
                ))}
            </div>
            <div className="grid gap-3 lg:grid-cols-2">
                <textarea
                    ref={textarea}
                    aria-label="Email HTML"
                    spellCheck={false}
                    value={html}
                    onChange={(event) => onChange(event.target.value, null)}
                    className="min-h-[32rem] w-full rounded-md border border-input bg-card p-3 font-mono text-xs leading-relaxed shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <iframe
                    title="Preview"
                    sandbox=""
                    srcDoc={html}
                    className="min-h-[32rem] w-full rounded-md border bg-white"
                />
            </div>
        </div>
    );
}
