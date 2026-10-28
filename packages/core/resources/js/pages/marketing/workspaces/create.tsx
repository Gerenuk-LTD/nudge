import { Form, Head } from '@inertiajs/react';
import WorkspaceController from '@/actions/Gerenuk/Marketing/Http/Controllers/WorkspaceController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function CreateWorkspace() {
    return (
        <>
            <Head title="Create a workspace" />

            <Form {...WorkspaceController.store.form()} className="space-y-6">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Workspace name</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                placeholder="Acme Ltd"
                            />
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
    title: 'Create a workspace',
    description:
        'A workspace holds your contacts, lists and campaigns. You can invite your team once it exists.',
};
