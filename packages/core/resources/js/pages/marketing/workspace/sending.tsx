import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SendingSettingsController from '@/actions/Gerenuk/Marketing/Http/Controllers/SendingSettingsController';
import InputError from '@/components/input-error';
import { SettingsSection } from '@marketing/components/settings-section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { edit } from '@/routes/marketing/sending';
import { ToggleSwitch } from '@marketing/components/toggle-switch';
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
                <SettingsSection
                    tone="danger"
                    title="Sending is paused"
                    badge={<Badge variant="destructive">Paused</Badge>}
                    description={`Paused ${formatDateTime(paused_at)}. ${paused_reason ?? ''}`}
                    hint="Campaigns in progress are held. Clean your list and check recent imports before resuming."
                    actions={
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    SendingSettingsController.resume.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Resume sending
                        </Button>
                    }
                />
            )}

            <SettingsForm
                postalAddress={postal_address}
                requiresApproval={requires_campaign_approval}
            />

            <SettingsSection
                title="Automatic pause"
                description={`Sending pauses on its own if, over the last 7 days and at least ${thresholds.minimum_sends.toLocaleString()} sends, bounces pass ${(thresholds.bounce_rate * 100).toFixed(1)}% or complaints pass ${(thresholds.complaint_rate * 100).toFixed(2)}%. This protects your provider accounts from suspension.`}
            />
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
        <form onSubmit={submit}>
            <SettingsSection
                title="Sending rules"
                description="Rules every campaign in this workspace follows."
                hint={form.recentlySuccessful && 'Saved.'}
                actions={
                    <Button size="sm" disabled={form.processing}>
                        Save
                    </Button>
                }
            >
                <div className="grid max-w-xl content-start gap-2">
                    <Label htmlFor="postal_address">Postal address</Label>
                    <Textarea
                        id="postal_address"
                        required
                        rows={3}
                        value={form.data.postal_address}
                        onChange={(event) =>
                            form.setData('postal_address', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Anti-spam law requires a physical address in every
                        campaign. Campaigns cannot be sent without it.
                    </p>
                    <InputError message={form.errors.postal_address} />
                </div>

                <div className="flex items-start justify-between gap-4 rounded-lg border bg-muted/30 px-3.5 py-3">
                    <div className="space-y-0.5">
                        <Label htmlFor="requires_approval" className="text-sm">
                            Require approval before a campaign is sent
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            Someone with the approve permission must sign off
                            each campaign. Editing a campaign withdraws its
                            approval.
                        </p>
                    </div>
                    <span className="pt-0.5">
                        <ToggleSwitch
                            id="requires_approval"
                            checked={form.data.requires_campaign_approval}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'requires_campaign_approval',
                                    checked,
                                )
                            }
                        />
                    </span>
                </div>
            </SettingsSection>
        </form>
    );
}

SendingSettings.layout = {
    breadcrumbs: [{ title: 'Sending settings', href: edit() }],
};
