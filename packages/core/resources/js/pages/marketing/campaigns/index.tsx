import type { ColumnDef } from '@tanstack/react-table';
import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, Plus, Send } from 'lucide-react';
import { useState } from 'react';
import CampaignController from '@/actions/Gerenuk/Marketing/Http/Controllers/CampaignController';
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
    index as campaignsIndex,
    edit,
    show,
} from '@/routes/marketing/campaigns';
import { CampaignStatusBadge } from '@marketing/components/campaign-status-badge';
import { byId, DataTable } from '@marketing/components/data-table';
import { EmptyState } from '@marketing/components/empty-state';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { Pagination } from '@marketing/components/pagination';
import {
    TemplatePicker,
    useTemplateOptions,
} from '@marketing/components/template-picker';
import {
    FacetedFilter,
    ResetFilters,
    TableSearch,
} from '@marketing/components/table-toolbar';
import { useTableQuery } from '@marketing/hooks/use-table-query';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDateTime, percent } from '@marketing/lib/format';
import type { CampaignSummary, Paginated } from '@marketing/types';

const STATUSES = [
    { value: 'sending', label: 'Sending' },
    { value: 'sent', label: 'Sent' },
    { value: 'cancelled', label: 'Cancelled' },
];

export default function CampaignsIndex({
    campaigns,
    upcoming,
}: {
    campaigns: Paginated<CampaignSummary>;
    upcoming: CampaignSummary[];
}) {
    const canCreate = useCan()('campaigns.manage');
    const table = useTableQuery();

    const columns: ColumnDef<CampaignSummary>[] = [
        {
            id: 'name',
            meta: { sortable: true },
            header: 'Campaign',
            cell: ({ row: { original: campaign } }) => (
                <>
                    <Link
                        href={show(campaign.id)}
                        className="font-medium hover:underline"
                    >
                        {campaign.name}
                    </Link>
                    {campaign.subject && (
                        <p className="max-w-md truncate text-xs text-muted-foreground">
                            {campaign.subject}
                        </p>
                    )}
                </>
            ),
        },
        {
            id: 'status',
            meta: { sortable: true },
            header: 'Status',
            cell: ({ row }) => (
                <CampaignStatusBadge status={row.original.status} />
            ),
        },
        {
            id: 'recipients',
            meta: { numeric: true, sortable: true },
            header: 'Recipients',
            cell: ({ row }) => row.original.stats.recipients.toLocaleString(),
        },
        {
            id: 'opened',
            meta: { numeric: true, sortable: true },
            header: 'Opened',
            cell: ({ row: { original: campaign } }) =>
                campaign.track_opens
                    ? percent(campaign.stats.opened, campaign.stats.sent)
                    : '—',
        },
        {
            id: 'clicked',
            meta: { numeric: true, sortable: true },
            header: 'Clicked',
            cell: ({ row: { original: campaign } }) =>
                campaign.track_clicks
                    ? percent(campaign.stats.clicked, campaign.stats.sent)
                    : '—',
        },
        {
            id: 'date',
            header: 'Date',
            meta: {
                className: 'whitespace-nowrap text-muted-foreground',
                sortable: true,
            },
            cell: ({ row: { original: campaign } }) =>
                formatDateTime(
                    campaign.sent_at ??
                        campaign.scheduled_at ??
                        campaign.created_at,
                ),
        },
        {
            id: 'actions',
            header: '',
            meta: { className: 'w-0 whitespace-nowrap text-right' },
            cell: ({ row: { original: campaign } }) => {
                const editable = campaign.status === 'draft' && canCreate;
                const label = editable
                    ? 'Edit'
                    : campaign.status === 'sent' ||
                        campaign.status === 'sending'
                      ? 'Report'
                      : 'View';

                return (
                    <div className="flex justify-end gap-1">
                        <Button variant="ghost" size="sm" asChild>
                            <Link
                                href={
                                    editable
                                        ? edit(campaign.id)
                                        : show(campaign.id)
                                }
                            >
                                {label}
                            </Link>
                        </Button>
                        {canCreate && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-muted-foreground"
                                onClick={() =>
                                    router.post(
                                        CampaignController.duplicate.url(
                                            campaign.id,
                                        ),
                                    )
                                }
                            >
                                Duplicate
                            </Button>
                        )}
                    </div>
                );
            },
        },
    ];

    const filtered = Boolean(table.get('search') || table.get('status'));
    const nothingYet =
        !filtered && upcoming.length === 0 && campaigns.total === 0;

    return (
        <>
            <Head title="Campaigns" />

            <PageBody>
                <PageHeader
                    title="Campaigns"
                    description="One-off emails to a list, a segment or everyone."
                    actions={canCreate && <NewCampaign />}
                />

                {nothingYet ? (
                    <EmptyState
                        icon={Send}
                        title="No campaigns yet"
                        description="A campaign is a one-off email to a list, a segment or everyone. Start from a template and send it now or schedule it."
                        actions={canCreate && <NewCampaign />}
                    />
                ) : (
                    <>
                        {(upcoming.length > 0 || canCreate) && (
                            <section className="space-y-3">
                                <h2 className="text-base font-semibold">
                                    In progress
                                </h2>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {upcoming.map((campaign) => (
                                        <UpcomingCard
                                            key={campaign.id}
                                            campaign={campaign}
                                            editable={canCreate}
                                        />
                                    ))}
                                    {canCreate && upcoming.length < 4 && (
                                        <NewCampaign variant="card" />
                                    )}
                                </div>
                            </section>
                        )}

                        <h2 className="text-base font-semibold">Sent</h2>

                        <div className="flex flex-wrap items-center gap-2">
                            <TableSearch
                                value={table.get('search')}
                                onSearch={(search) => table.update({ search })}
                                placeholder="Search name or subject"
                            />
                            <FacetedFilter
                                title="Status"
                                options={STATUSES}
                                selected={table.values('status')}
                                onChange={(status) => table.update({ status })}
                            />
                            <ResetFilters
                                active={table.values('status').length > 0}
                                onReset={() => table.update({ status: null })}
                            />
                        </div>

                        <DataTable
                            columns={columns}
                            data={campaigns.data}
                            getRowId={byId}
                            emptyMessage={
                                table.get('search') || table.get('status')
                                    ? 'No campaigns match these filters.'
                                    : 'Nothing sent yet. Campaigns appear here once they go out.'
                            }
                            sort={table.sort}
                        />

                        <Pagination page={campaigns} query={table} />
                    </>
                )}
            </PageBody>
        </>
    );
}

/**
 * A campaign that has not gone out yet: scheduled ones show when they send, drafts when they were last touched.
 */
function UpcomingCard({
    campaign,
    editable,
}: {
    campaign: CampaignSummary;
    editable: boolean;
}) {
    const scheduled = campaign.status === 'scheduled';

    return (
        <Link
            href={
                scheduled || !editable ? show(campaign.id) : edit(campaign.id)
            }
            className="group flex min-h-32 flex-col justify-between gap-4 rounded-xl border bg-card p-4 shadow-xs transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm"
        >
            <div className="space-y-1">
                <CampaignStatusBadge status={campaign.status} />
                <p className="pt-2 text-sm font-medium">{campaign.name}</p>
                <p className="truncate text-13 text-muted-foreground">
                    {campaign.subject || 'No subject yet'}
                </p>
            </div>
            <p className="flex items-center justify-between text-xs text-muted-foreground">
                {scheduled
                    ? `Goes out ${formatDateTime(campaign.scheduled_at)}`
                    : `Edited ${formatDateTime(campaign.updated_at ?? campaign.created_at)}`}
                <ArrowRight className="size-3.5 opacity-0 transition-opacity group-hover:opacity-100" />
            </p>
        </Link>
    );
}

/**
 * The new-campaign dialog, opened from the header button (which also opens it for `?new=1` links) or a dashed card.
 */
function NewCampaign({ variant = 'button' }: { variant?: 'button' | 'card' }) {
    const { url } = usePage();
    const query = new URLSearchParams(url.split('?')[1]);
    const requested = variant === 'button' && query.has('new');
    const [open, setOpen] = useState(requested);
    const [template, setTemplate] = useState(
        (requested && query.get('template')) || 'starter:simple',
    );
    const templateOptions = useTemplateOptions(open);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {variant === 'card' ? (
                    <button
                        type="button"
                        className="flex min-h-32 flex-col items-center justify-center gap-2 rounded-xl border border-dashed bg-muted/40 text-sm text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground"
                    >
                        <Plus className="size-4" />
                        New campaign
                    </button>
                ) : (
                    <Button>
                        <Plus />
                        New campaign
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogTitle>New campaign</DialogTitle>
                <Form
                    {...CampaignController.store.form()}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="campaign-name">Name</Label>
                                <Input
                                    id="campaign-name"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder="November newsletter"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Only your team sees this.
                                </p>
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label>Start from</Label>
                                <input
                                    type="hidden"
                                    name="template"
                                    value={template}
                                />
                                <TemplatePicker
                                    options={templateOptions}
                                    value={template}
                                    onChange={(choice) =>
                                        setTemplate(choice.key)
                                    }
                                />
                                <InputError message={errors.template} />
                            </div>
                            <DialogFooter>
                                <Button disabled={processing}>
                                    Create draft
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

CampaignsIndex.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
