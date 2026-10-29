import { Form, Head } from '@inertiajs/react';
import WorkspaceController from '@/actions/Gerenuk/Marketing/Http/Controllers/WorkspaceController';
import InputError from '@/components/input-error';
import { SettingsSection } from '@marketing/components/settings-section';
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

            <Form
                {...WorkspaceController.update.form()}
                options={{ preserveScroll: true }}
            >
                {({ processing, recentlySuccessful, errors }) => (
                    <SettingsSection
                        title="General"
                        description="The name everyone in this workspace sees, in the sidebar and in invitations."
                        hint={
                            recentlySuccessful
                                ? 'Saved.'
                                : !canEdit &&
                                  'Only people who can manage the workspace can rename it.'
                        }
                        actions={
                            canEdit && (
                                <Button size="sm" disabled={processing}>
                                    Save
                                </Button>
                            )
                        }
                    >
                        <fieldset
                            disabled={!canEdit}
                            className="grid gap-2 sm:max-w-sm"
                        >
                            <Label htmlFor="name">Workspace name</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={name}
                                required
                            />
                            <InputError message={errors.name} />
                        </fieldset>
                    </SettingsSection>
                )}
            </Form>
        </>
    );
}

WorkspaceSettings.layout = {
    breadcrumbs: [{ title: 'Workspace settings', href: edit() }],
};
