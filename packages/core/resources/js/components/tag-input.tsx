import { X } from 'lucide-react';
import { useId, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';

/**
 * Free-text tag entry with suggestions from existing tags. Press Enter or comma to add.
 */
export function TagInput({
    id,
    value,
    onChange,
    suggestions,
    placeholder = 'Add a tag',
}: {
    id?: string;
    value: string[];
    onChange: (tags: string[]) => void;
    suggestions: string[];
    placeholder?: string;
}) {
    const [draft, setDraft] = useState('');
    const listId = useId();

    const add = (raw: string) => {
        const tag = raw.trim();

        if (
            tag &&
            !value.some(
                (existing) => existing.toLowerCase() === tag.toLowerCase(),
            )
        ) {
            onChange([...value, tag]);
        }

        setDraft('');
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            add(draft);
        } else if (event.key === 'Backspace' && draft === '' && value.length) {
            onChange(value.slice(0, -1));
        }
    };

    return (
        <div className="space-y-2">
            {value.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {value.map((tag) => (
                        <Badge key={tag} variant="secondary" className="gap-1">
                            {tag}
                            <button
                                type="button"
                                aria-label={`Remove ${tag}`}
                                onClick={() =>
                                    onChange(value.filter((t) => t !== tag))
                                }
                            >
                                <X className="size-3" />
                            </button>
                        </Badge>
                    ))}
                </div>
            )}
            <Input
                id={id}
                value={draft}
                list={listId}
                placeholder={placeholder}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={onKeyDown}
                onBlur={() => add(draft)}
            />
            <datalist id={listId}>
                {suggestions
                    .filter((tag) => !value.includes(tag))
                    .map((tag) => (
                        <option key={tag} value={tag} />
                    ))}
            </datalist>
        </div>
    );
}
