import { Plus, Tag as TagIcon, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';

/** How many matching tags the menu lists at once. */
const MAX_OPTIONS = 8;

type TagOption = { name: string; create: boolean };

/**
 * Picks tags by searching the workspace's existing ones, with an option to create a new tag when nothing matches.
 * A name that matches an existing tag apart from its capitals uses that tag, so "VIP" and "vip" never both exist.
 * Enter or a click picks the highlighted option; a comma adds what is typed; Backspace on an empty box removes the last tag.
 */
export function TagInput({
    id,
    value,
    onChange,
    suggestions,
    placeholder = 'Search or create a tag',
    allowCreate = true,
}: {
    id?: string;
    value: string[];
    onChange: (tags: string[]) => void;
    suggestions: string[];
    placeholder?: string;
    /** Off where only existing tags make sense, such as removing tags. */
    allowCreate?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const listId = useId();
    const [draft, setDraft] = useState('');
    const [open, setOpen] = useState(false);
    const [highlighted, setHighlighted] = useState(0);

    const query = draft.trim();
    const lower = query.toLowerCase();
    const chosen = new Set(value.map((tag) => tag.toLowerCase()));
    const existing = (name: string) =>
        [...suggestions, ...value].find(
            (tag) => tag.toLowerCase() === name.toLowerCase(),
        );

    const matches = suggestions
        .filter(
            (tag) =>
                !chosen.has(tag.toLowerCase()) &&
                tag.toLowerCase().includes(lower),
        )
        // Tags that start with what is typed come before those that only contain it.
        .sort(
            (a, b) =>
                Number(!a.toLowerCase().startsWith(lower)) -
                Number(!b.toLowerCase().startsWith(lower)),
        )
        .slice(0, MAX_OPTIONS);

    const options: TagOption[] = [
        ...matches.map((name) => ({ name, create: false })),
        ...(allowCreate && query !== '' && !existing(query)
            ? [{ name: query, create: true }]
            : []),
    ];
    const active = Math.min(highlighted, options.length - 1);

    const add = (name: string) => {
        const tag = existing(name) ?? name.trim();

        if (tag !== '' && !chosen.has(tag.toLowerCase())) {
            onChange([...value, tag]);
        }

        setDraft('');
        setHighlighted(0);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                setOpen(true);
                setHighlighted((active + 1) % Math.max(options.length, 1));
                break;
            case 'ArrowUp':
                event.preventDefault();
                setHighlighted(
                    (active - 1 + options.length) % Math.max(options.length, 1),
                );
                break;
            case 'Enter':
                if (open && options[active]) {
                    event.preventDefault();
                    add(options[active].name);
                } else if (query !== '') {
                    event.preventDefault();
                }

                break;
            case ',':
                event.preventDefault();

                if (query !== '' && (allowCreate || existing(query))) {
                    add(query);
                }

                break;
            case 'Escape':
                if (open) {
                    event.preventDefault();
                    setOpen(false);
                }

                break;
            case 'Backspace':
                if (draft === '' && value.length > 0) {
                    onChange(value.slice(0, -1));
                }

                break;
        }
    };

    const showMenu = open && (options.length > 0 || query !== '');

    return (
        <div className="relative">
            <div
                onClick={() => input.current?.focus()}
                className="flex min-h-9 w-full cursor-text flex-wrap items-center gap-1.5 rounded-md border border-input bg-card px-2 py-1.5 shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 has-[input:disabled]:cursor-not-allowed has-[input:disabled]:opacity-50"
            >
                {value.map((tag) => (
                    <span
                        key={tag}
                        className="inline-flex h-6 items-center gap-1 rounded-md border bg-muted/60 pr-1 pl-2 text-xs font-medium"
                    >
                        {tag}
                        <button
                            type="button"
                            aria-label={`Remove ${tag}`}
                            onClick={(event) => {
                                event.stopPropagation();
                                onChange(value.filter((t) => t !== tag));
                            }}
                            className="rounded-sm p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <X className="size-3" />
                        </button>
                    </span>
                ))}
                <input
                    ref={input}
                    id={id}
                    role="combobox"
                    aria-expanded={showMenu}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        showMenu && options[active]
                            ? `${listId}-${active}`
                            : undefined
                    }
                    autoComplete="off"
                    value={draft}
                    placeholder={value.length === 0 ? placeholder : undefined}
                    onChange={(event) => {
                        setDraft(event.target.value);
                        setHighlighted(0);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => {
                        setOpen(false);

                        // Typing an existing tag's name in full and moving on still adds it.
                        if (query !== '' && existing(query)) {
                            add(query);
                        }
                    }}
                    onKeyDown={onKeyDown}
                    className="h-6 min-w-24 flex-1 bg-transparent px-1 text-sm outline-none placeholder:text-muted-foreground"
                />
            </div>

            {showMenu && (
                <ul
                    id={listId}
                    role="listbox"
                    className="absolute inset-x-0 top-full z-50 mt-1 max-h-64 overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    {options.length === 0 ? (
                        <li className="px-2 py-1.5 text-sm text-muted-foreground">
                            {chosen.has(lower)
                                ? `“${query}” is already added.`
                                : `No tag called “${query}”.`}
                        </li>
                    ) : (
                        options.map((option, index) => (
                            <li
                                key={`${option.create}-${option.name}`}
                                id={`${listId}-${index}`}
                                role="option"
                                aria-selected={index === active}
                                // Keeps focus in the box, so the menu does not close before the click lands.
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => add(option.name)}
                                onMouseMove={() => setHighlighted(index)}
                                className={cn(
                                    'flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm',
                                    index === active && 'bg-accent',
                                    option.create &&
                                        index > 0 &&
                                        'mt-1 border-t pt-2',
                                )}
                            >
                                {option.create ? (
                                    <>
                                        <Plus className="size-3.5 text-muted-foreground" />
                                        <span>
                                            Create tag{' '}
                                            <span className="font-medium">
                                                “{option.name}”
                                            </span>
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        <TagIcon className="size-3.5 text-muted-foreground" />
                                        {option.name}
                                    </>
                                )}
                            </li>
                        ))
                    )}
                </ul>
            )}
        </div>
    );
}
