import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import RoleController from '@/actions/Gerenuk/Marketing/Http/Controllers/RoleController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as rolesIndex } from '@/routes/marketing/roles';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import type { Permission } from '@marketing/types';

type Role = {
    id: number;
    name: string;
    permissions: Permission[];
    is_owner: boolean;
    members_count: number;
};

type PermissionOption = { value: Permission; label: string };

export default function Roles({
    roles,
    permissions,
}: {
    roles: Role[];
    permissions: PermissionOption[];
}) {
    const [editing, setEditing] = useState<Role | 'new' | null>(null);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <>
            <Head title="Roles" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Roles"
                        description="A role is a named set of permissions"
                    />
                    {editing === null && (
                        <Button onClick={() => setEditing('new')}>
                            New role
                        </Button>
                    )}
                </div>
                <InputError message={errors.role} />

                {editing !== null && (
                    <RoleEditor
                        key={editing === 'new' ? 'new' : editing.id}
                        role={editing === 'new' ? null : editing}
                        permissions={permissions}
                        onDone={() => setEditing(null)}
                    />
                )}

                <div className="divide-y rounded-xl border">
                    {roles.map((role) => (
                        <div
                            key={role.id}
                            className="flex flex-wrap items-start justify-between gap-4 p-4"
                        >
                            <div className="space-y-1.5">
                                <p className="font-medium">
                                    {role.name}{' '}
                                    <span className="text-sm font-normal text-muted-foreground">
                                        · {role.members_count}{' '}
                                        {role.members_count === 1
                                            ? 'member'
                                            : 'members'}
                                    </span>
                                </p>
                                <div className="flex flex-wrap gap-1">
                                    {role.is_owner ? (
                                        <Badge variant="secondary">
                                            Every permission, always
                                        </Badge>
                                    ) : (
                                        role.permissions.map((permission) => (
                                            <Badge
                                                key={permission}
                                                variant="outline"
                                            >
                                                {permission}
                                            </Badge>
                                        ))
                                    )}
                                </div>
                            </div>
                            {!role.is_owner && (
                                <div className="flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setEditing(role)}
                                    >
                                        Edit
                                    </Button>
                                    <ConfirmDelete
                                        url={RoleController.destroy.url(
                                            role.id,
                                        )}
                                        title={`Delete ${role.name}?`}
                                        description="Roles with members cannot be deleted. Move them to another role first."
                                    />
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

function RoleEditor({
    role,
    permissions,
    onDone,
}: {
    role: Role | null;
    permissions: PermissionOption[];
    onDone: () => void;
}) {
    const form = useForm<{ name: string; permissions: Permission[] }>({
        name: role?.name ?? '',
        permissions: role?.permissions ?? ['contacts.view'],
    });

    const toggle = (permission: Permission, checked: boolean) =>
        form.setData(
            'permissions',
            checked
                ? [...form.data.permissions, permission]
                : form.data.permissions.filter((p) => p !== permission),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            role ? RoleController.update(role.id) : RoleController.store(),
            {
                preserveScroll: true,
                onSuccess: onDone,
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-xl border p-4">
            <div className="grid max-w-sm gap-2">
                <Label htmlFor="role-name">Role name</Label>
                <Input
                    id="role-name"
                    required
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
                <InputError message={form.errors.name} />
            </div>

            <div className="grid gap-2">
                {permissions.map((permission) => (
                    <label
                        key={permission.value}
                        className="flex items-start gap-2 text-sm"
                    >
                        <Checkbox
                            className="mt-0.5"
                            checked={form.data.permissions.includes(
                                permission.value,
                            )}
                            onCheckedChange={(checked) =>
                                toggle(permission.value, checked === true)
                            }
                        />
                        <span>
                            {permission.label}
                            <code className="ml-2 text-xs text-muted-foreground">
                                {permission.value}
                            </code>
                        </span>
                    </label>
                ))}
                <InputError message={form.errors.permissions} />
            </div>

            <div className="flex gap-2">
                <Button disabled={form.processing}>Save role</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

Roles.layout = {
    breadcrumbs: [{ title: 'Roles', href: rolesIndex() }],
};
