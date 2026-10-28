import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SendingSettingsController from '@/actions/Gerenuk/Marketing/Http/Controllers/SendingSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/marketing/sending';
import { formatDateTime } from '@marketing/lib/format';

export default function SendingSettings({
    postal_address,
    requires_campaign_approval,
    paused_at,
    paused_reason,
    thresholds,
}: {
    postal_address: string | null;
    requires_campaign_approval: boolean;
    paused_at: string | null;
    paused_reason: string | null;
    thresholds: {
        minimum_sends: number;
        bounce_rate: number;
        complaint_rate: number;
    };
}) {
    return (
        <>
            <Head title="Sending settings" />

            {paused_at && (
                <div className="space-y-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/40">
                    <p className="font-medium">
                        Sending was paused {formatDateTime(paused_at)}
                    </p>
                    <p className="text-sm">{paused_reason}</p>
                    <p className="text-sm text-muted-foreground">
                        Campaigns in progress are held. Clean your list, check
                        recent imports, then resume.
                    </p>
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(SendingSettingsController.resume.url())
                        }
                    >
                        Resume sending
                    </Button>
                </div>
            )}

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Sending"
                    description="Rules every campaign in this workspace follows"
                />

                <SettingsForm
                    postalAddress={postal_address}
                    requiresApproval={requires_campaign_approval}
                />
            </div>

            <div className="space-y-2">
                <Heading variant="small" title="Automatic pause" />
                <p className="text-sm text-muted-foreground">
                    Sending pauses on its own if, over the last 7 days and at
                    least {thresholds.minimum_sends} sends, bounces pass{' '}
                    {(thresholds.bounce_rate * 100).toFixed(1)}% or complaints
                    pass {(thresholds.complaint_rate * 100).toFixed(2)}%. This
                    protects your provider account from suspension.
                </p>
            </div>
        </>
    );
}

function SettingsForm({
    postalAddress,
    requiresApproval,
}: {
    postalAddress: string | null;
    requiresApproval: boolean;
}) {
    const form = useForm({
        postal_address: postalAddress ?? '',
        requires_campaign_approval: requiresApproval,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(SendingSettingsController.update(), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-2">
                <Label htmlFor="postal_address">Postal address</Label>
                <textarea
                    id="postal_address"
                    required
                    rows={3}
                    value={form.data.postal_address}
                    onChange={(event) =>
                        form.setData('postal_address', event.target.value)
                    }
                    className="rounded-md border border-input bg-transparent p-3 text-sm shadow-xs"
                />
                <p className="text-xs text-muted-foreground">
                    Anti-spam law requires a physical address in every campaign.
                    Campaigns cannot be sent without it.
                </p>
                <InputError message={form.errors.postal_address} />
            </div>

            <label className="flex items-start gap-2 text-sm">
                <Checkbox
                    checked={form.data.requires_campaign_approval}
                    onCheckedChange={(checked) =>
                        form.setData(
                            'requires_campaign_approval',
                            checked === true,
                        )
                    }
                    className="mt-0.5"
                />
                <span>
                    Require approval before a campaign is sent
                    <span className="block text-xs text-muted-foreground">
                        Someone with the approve permission must sign off each
                        campaign. Editing a campaign withdraws its approval.
                    </span>
                </span>
            </label>

            <Button disabled={form.processing}>Save</Button>
        </form>
    );
}

SendingSettings.layout = {
    breadcrumbs: [{ title: 'Sending settings', href: edit() }],
};
