import { Form, Head } from '@inertiajs/react';
import ImportController from '@/actions/Gerenuk/Marketing/Http/Controllers/ImportController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index as importsIndex } from '@/routes/marketing/imports';
import { PageBody, PageHeader } from '@marketing/components/page-header';

export default function CreateImport({
    maxFileKilobytes,
}: {
    maxFileKilobytes: number;
}) {
    return (
        <>
            <Head title="Import contacts" />

            <PageBody>
                <PageHeader
                    title="Import contacts"
                    description="Upload a CSV with a header row. You will match its columns to contact fields next."
                />

                <Form
                    {...ImportController.store.form()}
                    className="max-w-xl space-y-6"
                >
                    {({ processing, progress, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="file">CSV file</Label>
                                <Input
                                    id="file"
                                    name="file"
                                    type="file"
                                    accept=".csv,text/csv"
                                    required
                                />
                                <p className="text-xs text-muted-foreground">
                                    Up to {Math.round(maxFileKilobytes / 1024)}{' '}
                                    MB. One row per contact, with an email
                                    address column.
                                </p>
                                <InputError message={errors.file} />
                            </div>

                            {progress && (
                                <progress
                                    className="w-full"
                                    value={progress.percentage}
                                    max={100}
                                />
                            )}

                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                Upload and continue
                            </Button>
                        </>
                    )}
                </Form>
            </PageBody>
        </>
    );
}

CreateImport.layout = {
    breadcrumbs: [{ title: 'Imports', href: importsIndex() }],
};
