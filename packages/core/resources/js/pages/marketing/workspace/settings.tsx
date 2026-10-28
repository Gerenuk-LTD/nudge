import { Form, Head } from '@inertiajs/react';
import WorkspaceController from '@/actions/Gerenuk/Marketing/Http/Controllers/WorkspaceController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/marketing/workspace';
import { useCan } from '@marketing/hooks/use-workspace';

export default function WorkspaceSettings({ name }: { name: string }) {
    const canEdit = useCan()('workspace.manage');

    return (
        <>
            <Head title="Workspace settings" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="General"
                    description="The name everyone in this workspace sees"
                />

                <Form
                    {...WorkspaceController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <fieldset disabled={!canEdit} className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Workspace name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={name}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
                            {canEdit && (
                                <Button disabled={processing}>Save</Button>
                            )}
                        </fieldset>
                    )}
                </Form>
            </div>
        </>
    );
}

WorkspaceSettings.layout = {
    breadcrumbs: [{ title: 'Workspace settings', href: edit() }],
};
