import { usePage } from '@inertiajs/react';
import type { Permission, WorkspaceSharedProps } from '@marketing/types';

/**
 * The current workspace and the signed-in user's permissions in it.
 * Null on pages outside a workspace, such as creating the first one.
 */
export function useWorkspace(): WorkspaceSharedProps | null {
    const { workspace } = usePage<{ workspace?: WorkspaceSharedProps }>().props;

    return workspace ?? null;
}

export function useCan(): (permission: Permission) => boolean {
    const workspace = useWorkspace();

    return (permission) => workspace?.permissions.includes(permission) ?? false;
}
