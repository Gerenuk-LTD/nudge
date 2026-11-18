<?php

namespace Gerenuk\Nudge\Jobs;

use Gerenuk\Nudge\Actions\Campaigns\RecordDeliveryEvent;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Sending\AutoPause;
use Gerenuk\Nudge\Sending\Webhooks\DeliveryEvent;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
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
        $this->onQueue(config('nudge.sending.queue'));
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
