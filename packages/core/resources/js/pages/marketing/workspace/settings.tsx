import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import WorkspaceController from '@/actions/Gerenuk/Marketing/Http/Controllers/WorkspaceController';
import InputError from '@/components/input-error';
import { SettingsSection } from '@marketing/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/marketing/workspace';
import { WorkspaceAvatar } from '@marketing/components/workspace-avatar';
import { useCan } from '@marketing/hooks/use-workspace';

export default function WorkspaceSettings({
    workspaceName,
}: {
    workspaceName: string;
}) {
    const canEdit = useCan()('workspace.manage');
    // The avatar follows the name as it is typed, so the new colour and initials show before saving.
    const [name, setName] = useState(workspaceName);

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
                            className="flex items-start gap-4"
                        >
                            <WorkspaceAvatar
                                workspace={{ name: name || '?' }}
                                className="mt-6 size-10 rounded-lg text-sm"
                            />
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="name">Workspace name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    value={name}
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
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
