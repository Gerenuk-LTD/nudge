import { Check, PlusCircle, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * A search box that searches as you type, after a short pause, or straight away on Enter.
 */
export function TableSearch({
    value,
    onSearch,
    placeholder = 'Search',
    className,
}: {
    value: string;
    onSearch: (term: string) => void;
    placeholder?: string;
    className?: string;
}) {
    const [term, setTerm] = useState(value);
    const timer = useRef<number | undefined>(undefined);

    const search = (next: string, delay: number) => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => onSearch(next.trim()), delay);
    };

    return (
        <form
            role="search"
            className={cn('w-full sm:w-64', className)}
            onSubmit={(event) => {
                event.preventDefault();
                search(term, 0);
            }}
        >
            <Input
                type="search"
                aria-label={placeholder}
                placeholder={placeholder}
                className="h-8"
                value={term}
                onChange={(event) => {
                    setTerm(event.target.value);
                    search(event.target.value, 300);
                }}
            />
        </form>
    );
}

export type FacetOption = { value: string; label: string };

/**
 * A dashed button that opens a checklist of values to filter a column by; rows matching any checked value are kept.
 */
export function FacetedFilter({
    title,
    options,
    selected,
    onChange,
}: {
    title: string;
    options: FacetOption[];
    selected: string[];
    onChange: (values: string[]) => void;
}) {
    if (options.length === 0) {
        return null;
    }

    const chosen = options.filter((option) => selected.includes(option.value));

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-8 border-dashed"
                >
                    <PlusCircle />
                    {title}
                    {chosen.length > 0 && (
                        <>
                            <span className="mx-0.5 h-4 w-px bg-border" />
                            {chosen.length > 2 ? (
                                <Badge
                                    variant="secondary"
                                    className="rounded-sm px-1 font-normal"
                                >
                                    {chosen.length} selected
                                </Badge>
                            ) : (
                                chosen.map((option) => (
                                    <Badge
                                        key={option.value}
                                        variant="secondary"
                                        className="rounded-sm px-1 font-normal"
                                    >
                                        {option.label}
                                    </Badge>
                                ))
                            )}
                        </>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-52">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    {title}
                </DropdownMenuLabel>
                {options.map((option) => (
                    <DropdownMenuCheckboxItem
                        key={option.value}
                        checked={selected.includes(option.value)}
                        onSelect={(event) => event.preventDefault()}
                        onCheckedChange={(checked) =>
                            onChange(
                                checked
                                    ? [...selected, option.value]
                                    : selected.filter(
                                          (value) => value !== option.value,
                                      ),
                            )
                        }
                    >
                        {option.label}
                    </DropdownMenuCheckboxItem>
                ))}
                {selected.length > 0 && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => onChange([])}>
                            <X />
                            Clear
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * One choice from a short list, such as active or archived contacts, shown like a faceted filter.
 */
export function ChoiceFilter({
    title,
    options,
    value,
    onChange,
}: {
    title: string;
    options: FacetOption[];
    value: string;
    onChange: (value: string) => void;
}) {
    if (options.length === 0) {
        return null;
    }

    const current = options.find((option) => option.value === value);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-8 border-dashed"
                >
                    <PlusCircle />
                    {title}
                    {current && (
                        <>
                            <span className="mx-0.5 h-4 w-px bg-border" />
                            <Badge
                                variant="secondary"
                                className="rounded-sm px-1 font-normal"
                            >
                                {current.label}
                            </Badge>
                        </>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-52">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    {title}
                </DropdownMenuLabel>
                {options.map((option) => (
                    <DropdownMenuItem
                        key={option.value}
                        onSelect={() =>
                            onChange(option.value === value ? '' : option.value)
                        }
                    >
                        <Check
                            className={cn(
                                option.value !== value && 'invisible',
                            )}
                        />
                        {option.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Clears every filter at once, shown only while one is set.
 */
export function ResetFilters({
    active,
    onReset,
}: {
    active: boolean;
    onReset: () => void;
}) {
    if (!active) {
        return null;
    }

    return (
        <Button variant="ghost" size="sm" className="h-8" onClick={onReset}>
            Reset
            <X />
        </Button>
    );
}
