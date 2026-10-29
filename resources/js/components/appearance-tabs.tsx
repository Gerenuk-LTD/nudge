import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

const themes: { value: Appearance; icon: LucideIcon; label: string }[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'system', icon: Monitor, label: 'System' },
];

/**
 * Theme choices as cards, each with a small sketch of the app in that theme.
 */
export default function AppearanceTabs() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div
            role="radiogroup"
            aria-label="Theme"
            className="grid gap-3 sm:grid-cols-3"
        >
            {themes.map(({ value, icon: Icon, label }) => {
                const selected = appearance === value;

                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => updateAppearance(value)}
                        className={cn(
                            'group overflow-hidden rounded-lg border text-left transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            selected
                                ? 'border-foreground ring-1 ring-foreground'
                                : 'hover:border-foreground/30',
                        )}
                    >
                        <ThemePreview theme={value} />
                        <span className="flex items-center gap-2 border-t px-3 py-2 text-sm font-medium">
                            <Icon className="size-4 text-muted-foreground" />
                            {label}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

function ThemePreview({ theme }: { theme: Appearance }) {
    if (theme === 'system') {
        return (
            <div className="relative h-24">
                <div className="absolute inset-0 [clip-path:polygon(0_0,100%_0,0_100%)]">
                    <ThemeSketch dark={false} />
                </div>
                <div className="absolute inset-0 [clip-path:polygon(100%_0,100%_100%,0_100%)]">
                    <ThemeSketch dark />
                </div>
            </div>
        );
    }

    return (
        <div className="h-24">
            <ThemeSketch dark={theme === 'dark'} />
        </div>
    );
}

/** Fixed colours rather than theme tokens, so each sketch shows its own theme whatever the current one is. */
function ThemeSketch({ dark }: { dark: boolean }) {
    return (
        <div
            className={cn(
                'flex h-full gap-2 p-2.5',
                dark ? 'bg-neutral-950' : 'bg-neutral-100',
            )}
        >
            <div
                className={cn(
                    'w-1/4 space-y-1.5 rounded-sm p-1.5',
                    dark ? 'bg-neutral-900' : 'bg-white',
                )}
            >
                {[0, 1, 2].map((line) => (
                    <div
                        key={line}
                        className={cn(
                            'h-1.5 rounded-full',
                            dark ? 'bg-neutral-700' : 'bg-neutral-200',
                        )}
                    />
                ))}
            </div>
            <div
                className={cn(
                    'flex-1 space-y-1.5 rounded-sm border p-2',
                    dark
                        ? 'border-neutral-800 bg-neutral-900'
                        : 'border-neutral-200 bg-white',
                )}
            >
                <div
                    className={cn(
                        'h-2 w-1/2 rounded-full',
                        dark ? 'bg-neutral-500' : 'bg-neutral-400',
                    )}
                />
                <div
                    className={cn(
                        'h-1.5 w-3/4 rounded-full',
                        dark ? 'bg-neutral-700' : 'bg-neutral-200',
                    )}
                />
                <div
                    className={cn(
                        'h-1.5 w-2/3 rounded-full',
                        dark ? 'bg-neutral-700' : 'bg-neutral-200',
                    )}
                />
            </div>
        </div>
    );
}
