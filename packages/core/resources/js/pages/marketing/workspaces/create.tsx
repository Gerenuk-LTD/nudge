import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import WorkspaceController from '@/actions/Gerenuk/Marketing/Http/Controllers/WorkspaceController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { WorkspaceAvatar } from '@marketing/components/workspace-avatar';

export default function CreateWorkspace() {
    const [name, setName] = useState('');

    return (
        <>
            <Head title="Create a workspace" />

            <Form {...WorkspaceController.store.form()} className="space-y-6">
                {({ processing, errors }) => (
                    <>
                        <div className="flex items-center gap-3 rounded-lg border bg-muted/40 p-3">
                            <WorkspaceAvatar
                                workspace={{ name: name || '?' }}
                                className="size-9 text-xs"
                            />
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium">
                                    {name || 'Your workspace'}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    How it shows in the sidebar
                                </p>
                            </div>
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="name">Workspace name</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                placeholder="Acme Ltd"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Usually your company or brand. You can change it
                                later.
                            </p>
                            <InputError message={errors.name} />
                        </div>

                        <Button className="w-full" disabled={processing}>
                            {processing && <Spinner />}
                            Create workspace
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

CreateWorkspace.layout = {
    title: 'Create your workspace',
    description:
        'A workspace holds your contacts, lists and campaigns. Invite your team once it exists.',
};
