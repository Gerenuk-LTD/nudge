<?php

use Illuminate\Console\Scheduling\Schedule;

test('marketing schedule entries hold their overlap lock for minutes, not a day', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_starts_with((string) $event->description, 'marketing:'));

    expect($events)->toHaveCount(7);

    foreach ($events as $event) {
        expect($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBeLessThanOrEqual(120);
    }
});
