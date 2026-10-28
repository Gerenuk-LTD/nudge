import { Form, Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
import { index as campaignsIndex, show } from '@/routes/marketing/campaigns';
import { CampaignStatusBadge } from '@marketing/components/campaign-status-badge';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { NativeSelect } from '@marketing/components/native-select';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { Pagination } from '@marketing/components/pagination';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDateTime, percent } from '@marketing/lib/format';
import type { CampaignSummary, Paginated } from '@marketing/types';

export default function CampaignsIndex({
    campaigns,
    status,
}: {
    campaigns: Paginated<CampaignSummary>;
    status: string | null;
}) {
    const canCreate = useCan()('campaigns.manage');

    return (
        <>
            <Head title="Campaigns" />

            <PageBody>
                <PageHeader
                    title="Campaigns"
                    description="One-off emails to a list, a segment or everyone."
                    actions={canCreate && <NewCampaign />}
                />

                <NativeSelect
                    aria-label="Status"
                    className="w-48"
                    value={status ?? ''}
                    onChange={(event) =>
                        router.get(
                            campaignsIndex.url(),
                            event.target.value
                                ? { status: event.target.value }
                                : {},
                            { preserveState: true, replace: true },
                        )
                    }
                >
                    <option value="">All campaigns</option>
                    <option value="draft">Drafts</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="sending">Sending</option>
                    <option value="sent">Sent</option>
                    <option value="cancelled">Cancelled</option>
                </NativeSelect>

                <DataTable
                    headers={[
                        'Campaign',
                        'Status',
                        'Recipients',
                        'Opened',
                        'Clicked',
                        'Date',
                    ]}
                    empty={
                        campaigns.data.length === 0 && (
                            <EmptyRow message="No campaigns yet." />
                        )
                    }
                >
                    {campaigns.data.map((campaign) => (
                        <tr key={campaign.id}>
                            <Cell>
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
                            </Cell>
                            <Cell>
                                <CampaignStatusBadge status={campaign.status} />
                            </Cell>
                            <Cell>
                                {campaign.stats.recipients.toLocaleString()}
                            </Cell>
                            <Cell>
                                {campaign.track_opens
                                    ? percent(
                                          campaign.stats.opened,
                                          campaign.stats.sent,
                                      )
                                    : '—'}
                            </Cell>
                            <Cell>
                                {campaign.track_clicks
                                    ? percent(
                                          campaign.stats.clicked,
                                          campaign.stats.sent,
                                      )
                                    : '—'}
                            </Cell>
                            <Cell className="whitespace-nowrap text-muted-foreground">
                                {formatDateTime(
                                    campaign.sent_at ??
                                        campaign.scheduled_at ??
                                        campaign.created_at,
                                )}
                            </Cell>
                        </tr>
                    ))}
                </DataTable>

                <Pagination page={campaigns} />
            </PageBody>
        </>
    );
}

function NewCampaign() {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    New campaign
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>New campaign</DialogTitle>
                <Form
                    {...CampaignController.store.form()}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
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
