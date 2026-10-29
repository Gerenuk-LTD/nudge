import { Form, Head } from '@inertiajs/react';
import CustomFieldController from '@/actions/Gerenuk/Marketing/Http/Controllers/CustomFieldController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as customFieldsIndex } from '@/routes/marketing/custom-fields';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { EditDialog } from '@marketing/components/edit-dialog';
import { NativeSelect } from '@/components/ui/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import {
    SettingsList,
    SettingsListItem,
} from '@marketing/components/settings-list';
import { useCan } from '@marketing/hooks/use-workspace';
import type { CustomField, CustomFieldType } from '@marketing/types';

export default function CustomFieldsIndex({
    fields,
    types,
}: {
    fields: (CustomField & { id: number })[];
    types: CustomFieldType[];
}) {
    const canEdit = useCan()('contacts.manage');

    return (
        <>
            <Head title="Custom fields" />

            <PageBody>
                <PageHeader
                    title="Custom fields"
                    description="Extra details stored on each contact. The key is used in the API and imports and cannot change."
                />

                {canEdit && (
                    <Form
                        {...CustomFieldController.store.form()}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="grid max-w-3xl items-end gap-3 sm:grid-cols-[1fr_1fr_auto_auto]"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="label">Label</Label>
                                    <Input
                                        id="label"
                                        name="label"
                                        placeholder="Company size"
                                        required
                                    />
                                    <InputError message={errors.label} />
                                </div>
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="key">Key</Label>
                                    <Input
                                        id="key"
                                        name="key"
                                        placeholder="company_size"
                                        pattern="[a-z][a-z0-9_]*"
                                        required
                                    />
                                    <InputError message={errors.key} />
                                </div>
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="type">Type</Label>
                                    <NativeSelect
                                        id="type"
                                        name="type"
                                        defaultValue="text"
                                    >
                                        {types.map((type) => (
                                            <option key={type} value={type}>
                                                {type.charAt(0).toUpperCase() +
                                                    type.slice(1)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={errors.type} />
                                </div>
                                <Button disabled={processing}>Add field</Button>
                            </>
                        )}
                    </Form>
                )}

                <SettingsList
                    className="max-w-3xl"
                    title="Fields"
                    description="Use a field in emails as {{ custom.key }}."
                    empty="No custom fields yet."
                >
                    {fields.map((field) => (
                        <SettingsListItem
                            key={field.id}
                            title={
                                <>
                                    {field.label}
                                    <Badge
                                        variant="outline"
                                        className="font-normal text-muted-foreground"
                                    >
                                        {field.type}
                                    </Badge>
                                </>
                            }
                            detail={
                                <code className="font-mono text-xs">
                                    {field.key}
                                </code>
                            }
                        >
                            {canEdit && (
                                <>
                                    <EditDialog
                                        title="Rename field"
                                        trigger="Rename"
                                        action={CustomFieldController.update.form(
                                            field.id,
                                        )}
                                        fields={[
                                            {
                                                name: 'label',
                                                label: 'Label',
                                                value: field.label,
                                            },
                                        ]}
                                    />
                                    <ConfirmDelete
                                        url={CustomFieldController.destroy.url(
                                            field.id,
                                        )}
                                        title={`Delete ${field.label}?`}
                                        description="The field disappears from forms, segments and the API. Values already stored stay on contacts until overwritten."
                                    />
                                </>
                            )}
                        </SettingsListItem>
                    ))}
                </SettingsList>
            </PageBody>
        </>
    );
}

CustomFieldsIndex.layout = {
    breadcrumbs: [{ title: 'Custom fields', href: customFieldsIndex() }],
};
