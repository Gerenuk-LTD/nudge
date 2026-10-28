import { Form, Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AutomationController from '@/actions/Gerenuk/Marketing/Http/Controllers/AutomationController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    edit,
    index as automationsIndex,
} from '@/routes/marketing/automations';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { NativeSelect } from '@marketing/components/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { useCan } from '@marketing/hooks/use-workspace';

type AutomationRow = {
    id: number;
    name: string;
    status: 'draft' | 'active' | 'paused';
    trigger: string;
    entered_count: number;
    completed_count: number;
    active_count: number;
};

type Trigger = { value: string; label: string };

export default function AutomationsIndex({
    automations,
    triggers,
}: {
    automations: AutomationRow[];
    triggers: Trigger[];
}) {
    const canManage = useCan()('automations.manage');

    return (
        <>
            <Head title="Automations" />

            <PageBody>
                <PageHeader
                    title="Automations"
                    description="Emails and updates that run on their own when something happens to a contact."
                    actions={canManage && <NewAutomation triggers={triggers} />}
                />

                <DataTable
                    headers={[
                        'Automation',
                        'Status',
                        'Starts when someone',
                        'In it now',
                        'Entered',
                        'Finished',
                    ]}
                    empty={
                        automations.length === 0 && (
                            <EmptyRow message="No automations yet. A welcome series is a good first one." />
                        )
                    }
                >
                    {automations.map((automation) => (
                        <tr key={automation.id}>
                            <Cell>
                                <Link
                                    href={edit(automation.id)}
                                    className="font-medium hover:underline"
                                >
                                    {automation.name}
                                </Link>
                            </Cell>
                            <Cell>
                                <Badge
                                    variant={
                                        automation.status === 'active'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {automation.status.charAt(0).toUpperCase() +
                                        automation.status.slice(1)}
                                </Badge>
                            </Cell>
                            <Cell className="text-muted-foreground">
                                {automation.trigger.toLowerCase()}
                            </Cell>
                            <Cell>
                                {automation.active_count.toLocaleString()}
                            </Cell>
                            <Cell>
                                {automation.entered_count.toLocaleString()}
                            </Cell>
                            <Cell>
                                {automation.completed_count.toLocaleString()}
                            </Cell>
                        </tr>
                    ))}
                </DataTable>
            </PageBody>
        </>
    );
}

function NewAutomation({ triggers }: { triggers: Trigger[] }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    New automation
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>New automation</DialogTitle>
                <Form
                    {...AutomationController.store.form()}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="automation-name">Name</Label>
                                <Input
                                    id="automation-name"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder="Welcome series"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="automation-trigger">
                                    Starts when someone
                                </Label>
                                <NativeSelect
                                    id="automation-trigger"
                                    name="trigger_type"
                                    defaultValue={triggers[0]?.value}
                                >
                                    {triggers.map((trigger) => (
                                        <option
                                            key={trigger.value}
                                            value={trigger.value}
                                        >
                                            {trigger.label.toLowerCase()}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <DialogFooter>
                                <Button disabled={processing}>Create</Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

AutomationsIndex.layout = {
    breadcrumbs: [{ title: 'Automations', href: automationsIndex() }],
};
