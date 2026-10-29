import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Copy, Crown, Ellipsis, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import RoleController from '@/actions/Gerenuk/Marketing/Http/Controllers/RoleController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index as rolesIndex } from '@/routes/marketing/roles';
import {
    PERMISSION_AREAS,
    PermissionPicker,
    permissionLevels,
    permissionSummary,
} from '@marketing/components/permission-picker';
import type { Permission } from '@marketing/types';

type Role = {
    id: number;
    name: string;
    permissions: Permission[];
    is_owner: boolean;
    members_count: number;
};

type PermissionOption = { value: Permission; label: string };

/** What the role dialog starts from: an existing role, or a new one, maybe copied from another. */
type Editing = { role: Role | null; name: string; permissions: Permission[] };

export default function Roles({
    roles,
    permissions,
}: {
    roles: Role[];
    permissions: PermissionOption[];
}) {
    const [editing, setEditing] = useState<Editing | null>(null);
    const [deleting, setDeleting] = useState<Role | null>(null);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <>
            <Head title="Roles" />

            <InputError message={errors.role} />

            <section className="rounded-xl border bg-card shadow-xs">
                <header className="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4 sm:px-6">
                    <div className="space-y-1">
                        <h2 className="text-base font-semibold">Roles</h2>
                        <p className="text-sm text-muted-foreground">
                            What each member can do. Everyone has one role.
                        </p>
                    </div>
                    <Button
                        size="sm"
                        onClick={() =>
                            setEditing({
                                role: null,
                                name: '',
                                permissions: ['contacts.view'],
                            })
                        }
                    >
                        <Plus />
                        New role
                    </Button>
                </header>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                <th className="py-2.5 pr-4 pl-5 font-medium sm:pl-6">
                                    Role
                                </th>
                                {PERMISSION_AREAS.map((area) => (
                                    <th
                                        key={area}
                                        className="hidden px-3 py-2.5 font-medium lg:table-cell"
                                    >
                                        {area}
                                    </th>
                                ))}
                                <th className="w-px py-2.5 pr-5 pl-3 sm:pr-6">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {roles.map((role) => (
                                <tr key={role.id} className="align-top">
                                    <td className="py-3.5 pr-4 pl-5 sm:pl-6">
                                        <p className="flex items-center gap-1.5 font-medium">
                                            {role.is_owner && (
                                                <Crown className="size-3.5 text-amber-500" />
                                            )}
                                            {role.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {role.members_count}{' '}
                                            {role.members_count === 1
                                                ? 'member'
                                                : 'members'}
                                        </p>
                                        {/* Below the wide layout the areas sit under the name instead of in columns. */}
                                        <p className="mt-1 text-xs text-muted-foreground lg:hidden">
                                            {role.is_owner
                                                ? 'Full access to everything'
                                                : permissionSummary(
                                                      role.permissions,
                                                  ).join(' · ') || 'No access'}
                                        </p>
                                    </td>
                                    {permissionLevels(role.permissions).map(
                                        (level) => (
                                            <td
                                                key={level.area}
                                                className="hidden px-3 py-3.5 lg:table-cell"
                                            >
                                                <AccessLevel
                                                    level={level.level}
                                                    label={level.label}
                                                />
                                            </td>
                                        ),
                                    )}
                                    <td className="py-3 pr-5 pl-3 sm:pr-6">
                                        <RoleMenu
                                            role={role}
                                            onEdit={() =>
                                                setEditing({
                                                    role,
                                                    name: role.name,
                                                    permissions:
                                                        role.permissions,
                                                })
                                            }
                                            onDuplicate={() =>
                                                setEditing({
                                                    role: null,
                                                    name: `${role.name} copy`,
                                                    permissions:
                                                        role.permissions,
                                                })
                                            }
                                            onDelete={() => setDeleting(role)}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p className="border-t bg-muted/40 px-5 py-3 text-xs text-muted-foreground sm:px-6">
                    Owners can do everything, and only an owner can make someone
                    else an owner.
                </p>
            </section>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent className="max-h-[calc(100svh-2rem)] overflow-y-auto sm:max-w-2xl">
                    {editing && (
                        <RoleForm
                            key={editing.role?.id ?? editing.name}
                            editing={editing}
                            permissions={permissions}
                            onDone={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogTitle>Delete {deleting?.name}?</DialogTitle>
                    <DialogDescription>
                        Nobody has this role, so nothing else changes.
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                deleting &&
                                router.delete(
                                    RoleController.destroy.url(deleting.id),
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setDeleting(null),
                                    },
                                )
                            }
                        >
                            Delete role
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function AccessLevel({
    level,
    label,
}: {
    level: 'full' | 'none' | 'some';
    label: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 text-13',
                level === 'none' && 'text-muted-foreground/60',
            )}
        >
            {level !== 'none' && (
                <span
                    aria-hidden
                    className={cn(
                        'size-1.5 shrink-0 rounded-full',
                        level === 'full' ? 'bg-emerald-500' : 'bg-sky-500',
                    )}
                />
            )}
            {level === 'none' ? (
                <>
                    <span aria-hidden>—</span>
                    <span className="sr-only">{label}</span>
                </>
            ) : (
                label
            )}
        </span>
    );
}

function RoleMenu({
    role,
    onEdit,
    onDuplicate,
    onDelete,
}: {
    role: Role;
    onEdit: () => void;
    onDuplicate: () => void;
    onDelete: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground"
                    aria-label={`Actions for ${role.name}`}
                >
                    <Ellipsis />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {!role.is_owner && (
                    <DropdownMenuItem onSelect={onEdit}>
                        <Pencil />
                        Edit
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem onSelect={onDuplicate}>
                    <Copy />
                    Duplicate
                </DropdownMenuItem>
                {!role.is_owner && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            disabled={role.members_count > 0}
                            onSelect={onDelete}
                        >
                            <Trash2 />
                            {role.members_count > 0
                                ? 'Delete (has members)'
                                : 'Delete'}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function RoleForm({
    editing,
    permissions,
    onDone,
}: {
    editing: Editing;
    permissions: PermissionOption[];
    onDone: () => void;
}) {
    const { role } = editing;
    const form = useForm<{ name: string; permissions: Permission[] }>({
        name: editing.name,
        permissions: editing.permissions,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            role ? RoleController.update(role.id) : RoleController.store(),
            { preserveScroll: true, onSuccess: onDone },
        );
    };

    return (
        <form onSubmit={submit} className="grid gap-5">
            <div className="space-y-1.5">
                <DialogTitle>
                    {role ? `Edit ${role.name}` : 'New role'}
                </DialogTitle>
                <DialogDescription>
                    {role && role.members_count > 0
                        ? `Changes apply straight away to the ${role.members_count} ${role.members_count === 1 ? 'member' : 'members'} with this role.`
                        : 'Choose what people with this role can do in the workspace.'}
                </DialogDescription>
            </div>
            <div className="grid max-w-sm content-start gap-2">
                <Label htmlFor="role-name">Role name</Label>
                <Input
                    id="role-name"
                    required
                    autoFocus
                    placeholder="Marketing assistant"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid content-start gap-2">
                <Label>Permissions</Label>
                <PermissionPicker
                    options={permissions}
                    value={form.data.permissions}
                    onChange={(value) => form.setData('permissions', value)}
                />
                <InputError message={form.errors.permissions} />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {role ? 'Save role' : 'Create role'}
                </Button>
            </DialogFooter>
        </form>
    );
}

Roles.layout = {
    breadcrumbs: [{ title: 'Roles', href: rolesIndex() }],
};
