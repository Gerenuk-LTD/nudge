import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Workflow } from 'lucide-react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import AutomationController from '@/actions/Gerenuk/Marketing/Http/Controllers/AutomationController';
import InputError from '@/components/input-error';
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
import { EmptyState } from '@marketing/components/empty-state';
import { stepKinds } from '@marketing/components/automation/step-kinds';
import { NativeSelect } from '@/components/ui/native-select';
import { cn } from '@/lib/utils';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { StarterCard, StarterGrid } from '@marketing/components/starter-grid';
import { StatusBadge } from '@marketing/components/status-badge';
import { useCan } from '@marketing/hooks/use-workspace';
import type { StepType } from '@marketing/types';

type AutomationRow = {
    id: number;
    name: string;
    status: 'draft' | 'active' | 'paused';
    trigger: string;
    entered_count: number;
    completed_count: number;
    active_count: number;
    outline: StepType[];
};

type Trigger = { value: string; label: string };

/** Common first automations: each makes a draft with this name and trigger, ready for its steps. */
const STARTERS = [
    {
        name: 'Welcome series',
        trigger: 'list_joined',
        description: 'Greet people when they join a list',
    },
    {
        name: 'Birthday email',
        trigger: 'date_field',
        description: 'Send something on a date in their details',
    },
    {
        name: 'Win back',
        trigger: 'inactivity',
        description: 'Reach out when someone stops engaging',
    },
    {
        name: 'Tag follow-up',
        trigger: 'tag_added',
        description: 'Follow up when a contact is given a tag',
    },
] as const;

export default function AutomationsIndex({
    automations,
    triggers,
}: {
    automations: AutomationRow[];
    triggers: Trigger[];
}) {
    const canManage = useCan()('automations.manage');
    const starters = STARTERS.filter((starter) =>
        triggers.some((trigger) => trigger.value === starter.trigger),
    );

    return (
        <>
            <Head title="Automations" />

            <PageBody>
                <PageHeader
                    title="Automations"
                    description="Emails and updates that run on their own when something happens to a contact."
                    actions={canManage && <NewAutomation triggers={triggers} />}
                />

                {automations.length === 0 ? (
                    <EmptyState
                        icon={Workflow}
                        title="No automations yet"
                        description="A welcome series is a good first one: an email when someone joins a list, then a follow-up a few days later."
                        actions={
                            canManage && (
                                <>
                                    <NewAutomation triggers={triggers} />
                                    {starters.length > 0 && (
                                        <StarterGrid>
                                            {starters.map((starter) => (
                                                <StarterCard
                                                    key={starter.name}
                                                    title={starter.name}
                                                    description={
                                                        starter.description
                                                    }
                                                    onClick={() =>
                                                        router.post(
                                                            AutomationController.store.url(),
                                                            {
                                                                name: starter.name,
                                                                trigger_type:
                                                                    starter.trigger,
                                                            },
                                                        )
                                                    }
                                                />
                                            ))}
                                        </StarterGrid>
                                    )}
                                </>
                            )
                        }
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {automations.map((automation) => (
                            <AutomationCard
                                key={automation.id}
                                automation={automation}
                            />
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

const statuses = {
    active: { label: 'Live', tone: 'success' },
    paused: { label: 'Paused', tone: 'warning' },
    draft: { label: 'Draft', tone: 'neutral' },
} as const;

/** How many steps the card's outline draws before summing up the rest. */
const OUTLINE_STEPS = 6;

/**
 * An automation at a glance: whether it is live, what starts it, the shape of its steps and how many people are in it.
 */
function AutomationCard({ automation }: { automation: AutomationRow }) {
    const shown = automation.outline.slice(0, OUTLINE_STEPS);
    const hidden = automation.outline.length - shown.length;
    const Trigger = stepKinds.trigger.icon;

    return (
        <Link
            href={edit(automation.id)}
            className="group flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-xs transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <p className="truncate text-sm font-semibold">
                        {automation.name}
                    </p>
                    <p className="truncate text-13 text-muted-foreground">
                        Starts when someone {automation.trigger.toLowerCase()}
                    </p>
                </div>
                <StatusBadge
                    tone={statuses[automation.status].tone}
                    live={automation.status === 'active'}
                >
                    {statuses[automation.status].label}
                </StatusBadge>
            </div>

            <div
                className="flex items-center"
                aria-label={`${automation.outline.length} steps`}
            >
                <OutlineTile tone={stepKinds.trigger.tone}>
                    <Trigger className="size-3.5" />
                </OutlineTile>
                {shown.map((type, index) => {
                    const Icon = stepKinds[type].icon;

                    return (
                        <Fragment key={index}>
                            <span className="h-px w-3 bg-border" />
                            <OutlineTile tone={stepKinds[type].tone}>
                                <Icon className="size-3.5" />
                            </OutlineTile>
                        </Fragment>
                    );
                })}
                {hidden > 0 && (
                    <span className="ml-2 text-xs text-muted-foreground">
                        +{hidden}
                    </span>
                )}
                {automation.outline.length === 0 && (
                    <span className="ml-3 text-xs text-muted-foreground">
                        No steps yet
                    </span>
                )}
            </div>

            <dl className="grid grid-cols-3 gap-2 border-t pt-4 text-xs text-muted-foreground">
                {(
                    [
                        ['In it now', automation.active_count],
                        ['Entered', automation.entered_count],
                        ['Finished', automation.completed_count],
                    ] as const
                ).map(([label, value]) => (
                    <div key={label}>
                        <dt>{label}</dt>
                        <dd className="text-sm font-medium text-foreground tabular-nums">
                            {value.toLocaleString()}
                        </dd>
                    </div>
                ))}
            </dl>
        </Link>
    );
}

function OutlineTile({
    tone,
    children,
}: {
    tone: string;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'flex size-7 shrink-0 items-center justify-center rounded-md',
                tone,
            )}
        >
            {children}
        </span>
    );
}

function NewAutomation({ triggers }: { triggers: Trigger[] }) {
    const { url } = usePage();

    return (
        <Dialog defaultOpen={new URLSearchParams(url.split('?')[1]).has('new')}>
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
                            <div className="grid content-start gap-2">
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
                            <div className="grid content-start gap-2">
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
