<?php

namespace Gerenuk\Marketing\Support;

use Closure;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Import;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Sending\WebhookVerification;

/**
 * What the home page flags: things that stop or harm sending, and work waiting on someone, each with a link to the
 * fix. Only notices the person can act on are included, most urgent first.
 */
class HomeNotices
{
    /** How far back a failed import is still worth flagging. */
    private const RECENT_DAYS = 7;

    /**
     * @param  Closure(Permission): bool  $can
     * @return list<array{key: string, type: 'info'|'warning'|'danger', heading: string, body: string, action: array{label: string, href: string}|null}>
     */
    public function for(Workspace $workspace, Closure $can): array
    {
        $manageSending = $can(Permission::ProvidersManage);
        $notices = [];

        if ($workspace->isSendingPaused()) {
            $notices[] = $this->notice(
                'paused',
                'danger',
                __('Sending is paused'),
                trim(($workspace->sending_paused_reason ?? '').' '.__('Campaigns and automation emails are held until someone resumes sending.')),
                $manageSending ? [__('Review and resume'), route('marketing.sending.edit')] : null,
            );
        }

        if (! Provider::query()->exists()) {
            $notices[] = $this->notice(
                'no-provider',
                'warning',
                __('No sending provider connected'),
                $manageSending
                    ? __('Connect the email service you send with before your first campaign.')
                    : __('Ask someone who manages sending to connect a provider.'),
                $manageSending ? [__('Connect provider'), route('marketing.providers.create')] : null,
            );
        }

        if (blank($workspace->postal_address)) {
            $notices[] = $this->notice(
                'no-postal-address',
                'warning',
                __('Add a postal address'),
                __('Anti-spam law requires one in every campaign, so campaigns cannot go out without it.'),
                $manageSending ? [__('Add it'), route('marketing.sending.edit')] : null,
            );
        }

        if ($manageSending) {
            $silent = Provider::query()
                ->whereNotNull('last_sent_at')
                ->whereNull('last_webhook_at')
                ->get()
                ->filter(fn (Provider $provider) => $provider->type->webhookVerification() !== WebhookVerification::None);

            foreach ($silent as $provider) {
                $notices[] = $this->notice(
                    "webhooks-{$provider->id}",
                    'warning',
                    __(':name is not reporting deliveries', ['name' => $provider->name]),
                    __('It has sent email, but no delivery webhooks have arrived, so bounces and complaints are not being recorded.'),
                    [__('Set up webhooks'), route('marketing.providers.show', $provider)],
                );
            }
        }

        if ($can(Permission::ContactsImport)) {
            $failed = Import::query()->where('status', ImportStatus::Failed)->where('updated_at', '>=', now()->subDays(self::RECENT_DAYS))->count();

            if ($failed > 0) {
                $notices[] = $this->notice(
                    'failed-imports',
                    'warning',
                    trans_choice('An import failed|:count imports failed', $failed),
                    __('Rows before the problem were saved. Open the import to see what went wrong.'),
                    [__('View imports'), route('marketing.imports.index')],
                );
            }

            $waiting = Import::query()->where('status', ImportStatus::Pending)->count();

            if ($waiting > 0) {
                $notices[] = $this->notice(
                    'pending-imports',
                    'info',
                    trans_choice('An upload is waiting for its columns|:count uploads are waiting for their columns', $waiting),
                    __('Match the columns to contact fields to bring the contacts in.'),
                    [__('Finish importing'), route('marketing.imports.index')],
                );
            }
        }

        return $notices;
    }

    /**
     * The first steps for a new workspace, while any are left to do, for people who can do them.
     *
     * @param  Closure(Permission): bool  $can
     * @return list<array{key: string, label: string, done: bool, href: string}>|null
     */
    public function setup(Workspace $workspace, Closure $can): ?array
    {
        if (! $can(Permission::ProvidersManage) || ! $can(Permission::CampaignsManage)) {
            return null;
        }

        $steps = [
            ['key' => 'provider', 'label' => __('Connect a sending provider'), 'done' => Provider::query()->exists(), 'href' => route('marketing.providers.create')],
            ['key' => 'address', 'label' => __('Add your postal address'), 'done' => filled($workspace->postal_address), 'href' => route('marketing.sending.edit')],
            ['key' => 'contacts', 'label' => __('Bring in your contacts'), 'done' => Contact::query()->exists(), 'href' => route('marketing.imports.create')],
            ['key' => 'campaign', 'label' => __('Send your first campaign'), 'done' => Campaign::query()->broadcasts()->where('status', CampaignStatus::Sent)->exists(), 'href' => route('marketing.campaigns.index', ['new' => 1])],
        ];

        return collect($steps)->every(fn (array $step) => $step['done']) ? null : $steps;
    }

    /**
     * @param  'info'|'warning'|'danger'  $type
     * @param  array{0: string, 1: string}|null  $action
     * @return array{key: string, type: 'info'|'warning'|'danger', heading: string, body: string, action: array{label: string, href: string}|null}
     */
    private function notice(string $key, string $type, string $heading, string $body, ?array $action): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'heading' => $heading,
            'body' => $body,
            'action' => $action === null ? null : ['label' => $action[0], 'href' => $action[1]],
        ];
    }
}
