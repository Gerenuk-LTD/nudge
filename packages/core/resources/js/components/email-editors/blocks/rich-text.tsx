import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    Bold,
    Braces,
    Italic,
    Link2,
    List,
    ListOrdered,
    Underline,
    Unlink,
} from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { MergeTag } from '../types';

/**
 * Inline rich text for a block, edited in place with a small formatting bar above it while selected. `singleLine`
 * suits headings: Enter does nothing and lists are off. Changes come back as HTML.
 */
export function RichText({
    html,
    onChange,
    selected,
    singleLine = false,
    toolbarEnd = false,
    mergeTags,
    style,
    className,
}: {
    html: string;
    onChange: (html: string) => void;
    selected: boolean;
    singleLine?: boolean;
    /** Open the formatting bar towards the left, for text near the email's right edge. */
    toolbarEnd?: boolean;
    mergeTags: MergeTag[];
    style?: CSSProperties;
    className?: string;
}) {
    // TipTap keeps the handlers it was created with, so read the latest one through a ref.
    const latestOnChange = useRef(onChange);
    latestOnChange.current = onChange;

    const editor = useEditor({
        immediatelyRender: false,
        extensions: [
            StarterKit.configure({
                heading: false,
                code: false,
                codeBlock: false,
                blockquote: false,
                horizontalRule: false,
                bulletList: singleLine ? false : undefined,
                orderedList: singleLine ? false : undefined,
                link: { openOnClick: false, autolink: true },
            }),
        ],
        content: singleLine ? `<p>${html}</p>` : html,
        editorProps: {
            attributes: { class: 'outline-none' },
            handleKeyDown: (_view, event) =>
                singleLine && event.key === 'Enter',
        },
        onUpdate: ({ editor: current }) => {
            const output = current.getHTML();

            latestOnChange.current(
                singleLine
                    ? output
                          .replace(/^<p>|<\/p>$/g, '')
                          .replace(/<\/p><p>/g, '<br>')
                    : output,
            );
        },
    });

    return (
        <div className="relative">
            {selected && editor && (
                <Toolbar
                    editor={editor}
                    end={toolbarEnd}
                    singleLine={singleLine}
                    mergeTags={mergeTags}
                />
            )}
            <EditorContent
                editor={editor}
                style={style}
                className={cn('email-rich', className)}
            />
        </div>
    );
}

type Editor = NonNullable<ReturnType<typeof useEditor>>;

function Toolbar({
    editor,
    end,
    singleLine,
    mergeTags,
}: {
    editor: Editor;
    end: boolean;
    singleLine: boolean;
    mergeTags: MergeTag[];
}) {
    const state = useEditorState({
        editor,
        selector: ({ editor: current }) => ({
            bold: current.isActive('bold'),
            italic: current.isActive('italic'),
            underline: current.isActive('underline'),
            link: current.isActive('link'),
            href:
                (current.getAttributes('link').href as string | undefined) ??
                '',
            bulletList: current.isActive('bulletList'),
            orderedList: current.isActive('orderedList'),
        }),
    });
    const [linking, setLinking] = useState(false);
    const [href, setHref] = useState('');

    const applyLink = () => {
        const chain = editor.chain().focus().extendMarkRange('link');

        if (href.trim() === '') {
            chain.unsetLink().run();
        } else {
            chain.setLink({ href: href.trim() }).run();
        }

        setLinking(false);
    };

    return (
        <div
            // Keep focus (and the text selection) in the editor while using the bar.
            onMouseDown={(event) => {
                if (!(event.target instanceof HTMLInputElement)) {
                    event.preventDefault();
                }
            }}
            className={cn(
                'absolute -top-11 z-20 flex items-center gap-0.5 rounded-lg border bg-popover p-1 font-sans text-popover-foreground shadow-md',
                end ? 'right-0' : 'left-0',
            )}
        >
            {linking ? (
                <form
                    className="flex items-center gap-1"
                    onSubmit={(event) => {
                        event.preventDefault();
                        applyLink();
                    }}
                >
                    <Input
                        autoFocus
                        value={href}
                        onChange={(event) => setHref(event.target.value)}
                        onKeyDown={(event) =>
                            event.key === 'Escape' && setLinking(false)
                        }
                        placeholder="https://"
                        aria-label="Link address"
                        className="h-7 w-56 text-13"
                    />
                    <Button type="submit" size="sm" className="h-7">
                        Apply
                    </Button>
                </form>
            ) : (
                <>
                    <Tool
                        label="Bold"
                        active={state.bold}
                        onClick={() =>
                            editor.chain().focus().toggleBold().run()
                        }
                    >
                        <Bold />
                    </Tool>
                    <Tool
                        label="Italic"
                        active={state.italic}
                        onClick={() =>
                            editor.chain().focus().toggleItalic().run()
                        }
                    >
                        <Italic />
                    </Tool>
                    <Tool
                        label="Underline"
                        active={state.underline}
                        onClick={() =>
                            editor.chain().focus().toggleUnderline().run()
                        }
                    >
                        <Underline />
                    </Tool>
                    <Tool
                        label={state.link ? 'Edit link' : 'Add link'}
                        active={state.link}
                        onClick={() => {
                            setHref(state.href || 'https://');
                            setLinking(true);
                        }}
                    >
                        <Link2 />
                    </Tool>
                    {state.link && (
                        <Tool
                            label="Remove link"
                            onClick={() =>
                                editor
                                    .chain()
                                    .focus()
                                    .extendMarkRange('link')
                                    .unsetLink()
                                    .run()
                            }
                        >
                            <Unlink />
                        </Tool>
                    )}
                    {!singleLine && (
                        <>
                            <span className="mx-0.5 h-5 w-px bg-border" />
                            <Tool
                                label="Bulleted list"
                                active={state.bulletList}
                                onClick={() =>
                                    editor
                                        .chain()
                                        .focus()
                                        .toggleBulletList()
                                        .run()
                                }
                            >
                                <List />
                            </Tool>
                            <Tool
                                label="Numbered list"
                                active={state.orderedList}
                                onClick={() =>
                                    editor
                                        .chain()
                                        .focus()
                                        .toggleOrderedList()
                                        .run()
                                }
                            >
                                <ListOrdered />
                            </Tool>
                        </>
                    )}
                    {mergeTags.length > 0 && (
                        <>
                            <span className="mx-0.5 h-5 w-px bg-border" />
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 gap-1.5 px-2 text-13"
                                    >
                                        <Braces />
                                        Insert field
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="start"
                                    onCloseAutoFocus={(event) =>
                                        event.preventDefault()
                                    }
                                >
                                    {mergeTags.map((tag) => (
                                        <DropdownMenuItem
                                            key={tag.tag}
                                            onSelect={() =>
                                                editor
                                                    .chain()
                                                    .focus()
                                                    .insertContent(tag.tag)
                                                    .run()
                                            }
                                        >
                                            {tag.label}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </>
                    )}
                </>
            )}
        </div>
    );
}

function Tool({
    label,
    active = false,
    onClick,
    children,
}: {
    label: string;
    active?: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label={label}
            aria-pressed={active}
            title={label}
            onClick={onClick}
            className={cn('size-7', active && 'bg-muted text-foreground')}
        >
            {children}
        </Button>
    );
}
