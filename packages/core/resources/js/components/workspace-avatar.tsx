import { cn } from '@/lib/utils';

/**
 * A rounded tile with the workspace's initials, tinted by a hue derived from its name, so a workspace keeps its colour
 * and a preview of a new one matches what it becomes.
 */
export function WorkspaceAvatar({
    workspace,
    className,
}: {
    workspace: { name: string };
    className?: string;
}) {
    const hue =
        Array.from(workspace.name).reduce(
            (hash, character) => (hash * 31 + character.codePointAt(0)!) % 360,
            7,
        ) % 360;
    const initials = workspace.name
        .split(/[\s:]+/u)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => Array.from(word)[0])
        .join('')
        .toUpperCase();

    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex size-7 shrink-0 items-center justify-center rounded-md text-[11px] font-semibold text-white shadow-xs',
                className,
            )}
            style={{ backgroundColor: `oklch(0.55 0.14 ${hue})` }}
        >
            {initials || '?'}
        </span>
    );
}
