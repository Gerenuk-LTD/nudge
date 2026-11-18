<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Actions\Campaigns\RecordDeliveryEvent;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Sending\AutoPause;
use Gerenuk\Marketing\Sending\Webhooks\DeliveryEvent;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDeliveryEvents implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  list<DeliveryEvent>  $events
     */
    public function __construct(public int $workspaceId, public array $events)
    {
        $this->onQueue(config('marketing.sending.queue'));
    }

    public function handle(CurrentWorkspace $currentWorkspace, RecordDeliveryEvent $record, AutoPause $autoPause): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);

        $currentWorkspace->runAs($workspace, function () use ($workspace, $record, $autoPause): void {
            foreach ($this->events as $event) {
                $record->handle($event);
            }

            $badNews = array_filter($this->events, fn (DeliveryEvent $event) => in_array($event->type, [EventType::Bounced, EventType::Complained], true));

            if ($badNews !== []) {
                $autoPause->check($workspace);
            }
        });
    }
}
