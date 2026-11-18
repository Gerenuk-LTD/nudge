import { Checkbox } from '@/components/ui/checkbox';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    PERMISSION_GROUPS,
    groupCoverage,
    toggleGroup,
    togglePermission,
} from '@marketing/lib/permissions';
import type { Permission } from '@marketing/types';

export type PermissionOption = {
    value: Permission;
    label: string;
    description: string;
};

/**
 * The permissions as a list grouped by area, with a checkbox for each whole area and a description under each
 * permission. Granting something also grants seeing it, and taking away seeing something takes away what relies
 * on it. When `grantable` is given, anything outside it is shown but can't be ticked.
 */
export function PermissionPicker({
    options,
    value,
    onChange,
    grantable,
}: {
    options: PermissionOption[];
    value: Permission[];
    onChange: (permissions: Permission[]) => void;
    grantable?: Permission[];
}) {
    const order = options.map((option) => option.value);
    const canGrant = (permission: Permission) =>
        grantable === undefined || grantable.includes(permission);

    return (
        <div className="grid gap-5">
            {PERMISSION_GROUPS.map((group) => {
                const permissions = options.filter((option) =>
                    group.permissions.some(
                        ([permission]) => permission === option.value,
                    ),
                );

                if (permissions.length === 0) {
                    return null;
                }

                const groupValues = permissions.map((option) => option.value);
                const coverage = groupCoverage(value, groupValues);
                const grantableValues = groupValues.filter(canGrant);

                return (
                    <fieldset key={group.title} className="grid">
                        <div className="flex items-center justify-between gap-3 border-b pb-2">
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    disabled={grantableValues.length === 0}
                                    checked={
                                        coverage.granted === coverage.total
                                            ? true
                                            : coverage.granted === 0
                                              ? false
                                              : 'indeterminate'
                                    }
                                    onCheckedChange={() =>
                                        onChange(
                                            toggleGroup(
                                                value,
                                                grantableValues,
                                                coverage.granted !==
                                                    coverage.total,
                                                order,
                                            ),
                                        )
                                    }
                                />
                                <span className="font-mono text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    {group.title}
                                </span>
                            </label>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {coverage.granted} of {coverage.total}
                            </span>
                        </div>
                        <div className="divide-y">
                            {permissions.map((option) => (
                                <PermissionRow
                                    key={option.value}
                                    option={option}
                                    checked={value.includes(option.value)}
                                    disabled={!canGrant(option.value)}
                                    onChange={(on) =>
                                        onChange(
                                            togglePermission(
                                                value,
                                                option.value,
                                                on,
                                                order,
                                            ),
                                        )
                                    }
                                />
                            ))}
                        </div>
                    </fieldset>
                );
            })}
        </div>
    );
}

function PermissionRow({
    option,
    checked,
    disabled,
    onChange,
}: {
    option: PermissionOption;
    checked: boolean;
    disabled: boolean;
    onChange: (on: boolean) => void;
}) {
    const row = (
        <label
            className={cn(
                'grid grid-cols-[auto_1fr] items-start gap-x-3 py-3',
                disabled && 'cursor-not-allowed',
            )}
        >
            <Checkbox
                className="mt-0.5"
                checked={checked}
                disabled={disabled}
                onCheckedChange={(state) => onChange(state === true)}
            />
            <span className={cn('grid gap-0.5', disabled && 'opacity-50')}>
                <span className="text-sm">{option.label}</span>
                <span className="text-xs text-muted-foreground">
                    {option.description}
                </span>
            </span>
        </label>
    );

    if (!disabled) {
        return row;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{row}</TooltipTrigger>
            <TooltipContent>
                You can't give out a permission you don't have.
            </TooltipContent>
        </Tooltip>
    );
}
