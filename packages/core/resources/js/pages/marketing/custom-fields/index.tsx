import { Form, Head } from '@inertiajs/react';
import CustomFieldController from '@/actions/Gerenuk/Marketing/Http/Controllers/CustomFieldController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as customFieldsIndex } from '@/routes/marketing/custom-fields';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { EditDialog } from '@marketing/components/edit-dialog';
import { NativeSelect } from '@marketing/components/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
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
                                <div className="grid gap-1.5">
                                    <Label htmlFor="label">Label</Label>
                                    <Input
                                        id="label"
                                        name="label"
                                        placeholder="Company size"
                                        required
                                    />
                                    <InputError message={errors.label} />
                                </div>
                                <div className="grid gap-1.5">
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
                                <div className="grid gap-1.5">
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

                <DataTable
                    headers={['Label', 'Key', 'Type', '']}
                    empty={
                        fields.length === 0 && (
                            <EmptyRow message="No custom fields yet." />
                        )
                    }
                >
                    {fields.map((field) => (
                        <tr key={field.id}>
                            <Cell className="font-medium">{field.label}</Cell>
                            <Cell>
                                <code className="text-xs">{field.key}</code>
                            </Cell>
                            <Cell>
                                <Badge variant="outline">{field.type}</Badge>
                            </Cell>
                            <Cell className="text-right whitespace-nowrap">
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
                            </Cell>
                        </tr>
                    ))}
                </DataTable>
            </PageBody>
        </>
    );
}

CustomFieldsIndex.layout = {
    breadcrumbs: [{ title: 'Custom fields', href: customFieldsIndex() }],
};
