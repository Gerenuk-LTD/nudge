import { plural } from '@nudge/lib/format';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Check,
    ChevronRight,
    Copy,
    Crown,
    Ellipsis,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import type { FormEvent } from 'react';
import RoleController from '@/actions/Gerenuk/Nudge/Http/Controllers/RoleController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
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
import { index as rolesIndex } from '@/routes/nudge/roles';
import { ConfirmDialog } from '@nudge/components/confirm-dialog';
import { PermissionPicker } from '@nudge/components/permission-picker';
import type { PermissionOption } from '@nudge/components/permission-picker';
import {
    PERMISSION_GROUPS,
    countChanges,
    groupCoverage,
} from '@nudge/lib/permissions';
import type { Permission } from '@nudge/types';

type Role = {
    id: number;
    name: string;
    key: string | null;
    description: string | null;
    permissions: Permission[];
    is_owner: boolean;
    is_builtin: boolean;
    members_count: number;
};

/** What the role dialog starts from: an existing role, or a new one, maybe copied from another. */
type Editing = {
    role: Role | null;
    name: string;
    description: string;
    permissions: Permission[];
};

export default function Roles({
    roles,
    permissions,
    grantable,
}: {
    roles: Role[];
    permissions: PermissionOption[];
    grantable: Permission[];
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
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Built-in roles can't be renamed or deleted, but you
                            can change what Admin, Editor and Viewer are allowed
                            to do. Create a custom role for anything else.
                        </p>
                    </div>
                    <Button
                        size="sm"
                        onClick={() =>
                            setEditing({
                                role: null,
                                name: '',
                                description: '',
                                permissions: ['contacts.view'],
                            })
                        }
                    >
                        <Plus />
                        New role
                    </Button>
                </header>

                <ul className="divide-y">
                    {roles.map((role) => (
                        <li
                            key={role.id}
                            className="flex items-start justify-between gap-3 px-5 py-4 sm:px-6"
                        >
                            <div className="min-w-0 space-y-1">
                                <p className="flex flex-wrap items-center gap-2 font-medium">
                                    {role.is_owner && (
                                        <Crown className="size-3.5 text-amber-500" />
                                    )}
                                    {role.name}
                                    {role.is_builtin && (
                                        <Badge
                                            variant="outline"
                                            className="font-mono text-[10px] tracking-wide uppercase"
                                        >
                                            Built-in
                                        </Badge>
                                    )}
                                </p>
                                {role.description && (
                                    <p className="text-sm text-muted-foreground">
                                        {role.description}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    {plural(
                                        role.members_count,
                                        'member',
                                        'members',
                                    )}{' '}
                                    · {role.permissions.length} of{' '}
                                    {permissions.length} permissions
                                </p>
                            </div>
                            <RoleMenu
                                role={role}
                                onEdit={() =>
                                    setEditing({
                                        role,
                                        name: role.name,
                                        description: role.description ?? '',
                                        permissions: role.permissions,
                                    })
                                }
                                onDuplicate={() =>
                                    setEditing({
                                        role: null,
                                        name: `${role.name} copy`,
                                        description: role.description ?? '',
                                        permissions: role.permissions,
                                    })
                                }
                                onDelete={() => setDeleting(role)}
                            />
                        </li>
                    ))}
                    {!roles.some((role) => !role.is_builtin) && (
                        <li className="px-5 py-4 text-sm text-muted-foreground sm:px-6">
                            No custom roles yet.
                        </li>
                    )}
                </ul>
            </section>

            <CompareRoles roles={roles} permissions={permissions} />

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent className="flex max-h-[calc(100svh-2rem)] flex-col gap-0 p-0 sm:max-w-xl">
                    {editing && (
                        <RoleForm
                            key={editing.role?.id ?? editing.name}
                            editing={editing}
                            permissions={permissions}
                            grantable={grantable}
                            onDone={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={<>Delete {deleting?.name}?</>}
                description="Nobody has this role, so nothing else changes."
                confirmLabel="Delete role"
                onConfirm={() =>
                    deleting &&
                    router.delete(RoleController.destroy.url(deleting.id), {
                        preserveScroll: true,
                        onFinish: () => setDeleting(null),
                    })
                }
            />
        </>
    );
}

/** A permission matrix: the groups (and, opened up, their permissions) down the side and one column per role. */
function CompareRoles({
    roles,
    permissions,
}: {
    roles: Role[];
    permissions: PermissionOption[];
}) {
    const [expanded, setExpanded] = useState<Set<string>>(new Set());

    const groups = PERMISSION_GROUPS.map((group) => ({
        title: group.title,
        options: permissions.filter((option) =>
            group.permissions.some(
                ([permission]) => permission === option.value,
            ),
        ),
    })).filter((group) => group.options.length > 0);

    const allOpen = groups.every((group) => expanded.has(group.title));

    const toggle = (title: string) =>
        setExpanded((current) => {
            const next = new Set(current);

            if (!next.delete(title)) {
                next.add(title);
            }

            return next;
        });

    return (
        <section className="mt-6 rounded-xl border bg-card shadow-xs">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4 sm:px-6">
                <div className="space-y-1">
                    <h2 className="text-base font-semibold">Compare roles</h2>
                    <p className="text-sm text-muted-foreground">
                        What each role can do. Expand a group to see its
                        individual permissions.
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        setExpanded(
                            allOpen
                                ? new Set()
                                : new Set(groups.map((group) => group.title)),
                        )
                    }
                >
                    {allOpen ? 'Collapse all' : 'Expand all'}
                </Button>
            </header>

            <div className="overflow-x-auto rounded-b-xl">
                <table className="w-full border-separate border-spacing-0 text-sm [&_tbody_tr:last-child>*]:border-b-0">
                    <thead>
                        <tr>
                            <th
                                scope="col"
                                className="sticky left-0 z-10 min-w-64 border-r border-b bg-muted/40 py-2.5 pr-4 pl-5 text-left font-mono text-xs font-medium tracking-wide text-muted-foreground uppercase sm:pl-6"
                            >
                                Permission
                            </th>
                            {roles.map((role) => (
                                <th
                                    key={role.id}
                                    scope="col"
                                    className="border-b bg-muted/40 px-4 py-2.5 text-center text-xs font-medium whitespace-nowrap"
                                >
                                    {role.name}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {groups.map((group) => {
                            const open = expanded.has(group.title);
                            const panel = `compare-${group.title}`;
                            const values = group.options.map(
                                (option) => option.value,
                            );

                            return (
                                <Fragment key={group.title}>
                                    <tr>
                                        <th
                                            scope="row"
                                            className="sticky left-0 z-10 border-r border-b bg-card py-2.5 pr-4 pl-3 text-left font-medium sm:pl-4"
                                        >
                                            <button
                                                type="button"
                                                aria-expanded={open}
                                                aria-controls={panel}
                                                onClick={() =>
                                                    toggle(group.title)
                                                }
                                                className="flex items-center gap-1.5 rounded-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            >
                                                <ChevronRight
                                                    className={cn(
                                                        'size-4 text-muted-foreground transition-transform',
                                                        open && 'rotate-90',
                                                    )}
                                                />
                                                {group.title}
                                            </button>
                                        </th>
                                        {roles.map((role) => {
                                            const { label } = groupCoverage(
                                                role.permissions,
                                                values,
                                            );

                                            return (
                                                <td
                                                    key={role.id}
                                                    className={cn(
                                                        'border-b px-4 py-2.5 text-center text-13',
                                                        label === 'None' &&
                                                            'text-muted-foreground',
                                                    )}
                                                >
                                                    {label}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                    {open &&
                                        group.options.map((option, index) => (
                                            <tr
                                                key={option.value}
                                                id={
                                                    index === 0
                                                        ? panel
                                                        : undefined
                                                }
                                            >
                                                <th
                                                    scope="row"
                                                    className="sticky left-0 z-10 border-r border-b bg-card py-2.5 pr-4 pl-9 text-left font-normal sm:pl-10"
                                                >
                                                    <span className="block text-sm">
                                                        {option.label}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {option.description}
                                                    </span>
                                                </th>
                                                {roles.map((role) => (
                                                    <td
                                                        key={role.id}
                                                        className="border-b px-4 py-2.5 text-center"
                                                    >
                                                        {role.permissions.includes(
                                                            option.value,
                                                        ) ? (
                                                            <>
                                                                <Check className="mx-auto size-4 text-primary" />
                                                                <span className="sr-only">
                                                                    Allowed
                                                                </span>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <span
                                                                    aria-hidden
                                                                    className="text-muted-foreground"
                                                                >
                                                                    —
                                                                </span>
                                                                <span className="sr-only">
                                                                    Not allowed
                                                                </span>
                                                            </>
                                                        )}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                </Fragment>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
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
                    className="size-8 shrink-0 text-muted-foreground"
                    aria-label={`Actions for ${role.name}`}
                >
                    <Ellipsis />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {!role.is_owner && (
                    <DropdownMenuItem onSelect={onEdit}>
                        <Pencil />
                        {role.is_builtin ? 'Edit permissions' : 'Edit'}
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem onSelect={onDuplicate}>
                    <Copy />
                    Duplicate
                </DropdownMenuItem>
                {!role.is_builtin && (
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
    grantable,
    onDone,
}: {
    editing: Editing;
    permissions: PermissionOption[];
    grantable: Permission[];
    onDone: () => void;
}) {
    const { role } = editing;
    const builtIn = role?.is_builtin ?? false;
    const form = useForm<{
        name: string;
        description: string;
        permissions: Permission[];
    }>({
        name: editing.name,
        description: editing.description,
        permissions: editing.permissions,
    });

    const changes =
        countChanges(editing.permissions, form.data.permissions) +
        (form.data.name !== editing.name ? 1 : 0) +
        (form.data.description !== editing.description ? 1 : 0);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            role ? RoleController.update(role.id) : RoleController.store(),
            { preserveScroll: true, onSuccess: onDone },
        );
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <div className="space-y-1.5 border-b px-6 py-5 pr-12">
                <DialogTitle>
                    {role
                        ? builtIn
                            ? `Edit '${role.name}' permissions`
                            : `Edit ${role.name}`
                        : 'New role'}
                </DialogTitle>
                <DialogDescription>
                    {builtIn &&
                        "Built-in roles keep their name, but you can choose what they're allowed to do. "}
                    {role
                        ? `Changes apply straight away to ${plural(role.members_count, 'member', 'members')} with this role.`
                        : 'Choose what people with this role can do in the workspace.'}
                </DialogDescription>
            </div>

            <div className="grid min-h-0 flex-1 content-start gap-5 overflow-y-auto px-6 py-5">
                {!builtIn && (
                    <>
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
                            <Label htmlFor="role-description">
                                Description
                            </Label>
                            <Input
                                id="role-description"
                                maxLength={255}
                                placeholder="What this role is for"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.description} />
                        </div>
                    </>
                )}
                <div className="grid content-start gap-2">
                    <PermissionPicker
                        options={permissions}
                        value={form.data.permissions}
                        onChange={(value) => form.setData('permissions', value)}
                        grantable={grantable}
                    />
                    <InputError message={form.errors.permissions} />
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-4">
                <p className="text-sm text-muted-foreground">
                    {changes === 0
                        ? 'No changes'
                        : plural(changes, 'change', 'changes')}
                </p>
                <div className="flex gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="ghost">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        disabled={form.processing || (!!role && changes === 0)}
                    >
                        {form.processing && <Spinner />}
                        {role ? 'Save changes' : 'Create role'}
                    </Button>
                </div>
            </div>
        </form>
    );
}

Roles.layout = {
    breadcrumbs: [{ title: 'Roles', href: rolesIndex() }],
};
