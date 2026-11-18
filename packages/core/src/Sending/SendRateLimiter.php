<?php

namespace Gerenuk\Nudge\Sending;

use Gerenuk\Nudge\Models\Provider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Keeps each provider within its per-second rate and daily cap, across every queue worker.
 */
class SendRateLimiter
{
    public function waitForSlot(Provider $provider): void
    {
        $key = "nudge:provider-rate:{$provider->id}";

        while (! RateLimiter::attempt($key, max(1, $provider->max_per_second), fn () => true, 1)) {
            Sleep::for(RateLimiter::availableIn($key) * 1000 ?: 100)->milliseconds();
        }
    }

    public function allowsToday(Provider $provider): bool
    {
        return $provider->daily_limit === null || $this->sentToday($provider) < $provider->daily_limit;
    }

    public function recordSend(Provider $provider): void
    {
        Cache::add($this->dailyKey($provider), 0, now()->endOfDay());
        Cache::increment($this->dailyKey($provider));
    }

    public function sentToday(Provider $provider): int
    {
        return (int) Cache::get($this->dailyKey($provider), 0);
    }

    public function secondsUntilTomorrow(): int
    {
        return (int) now()->diffInSeconds(now()->endOfDay()) + 60;
    }

    private function dailyKey(Provider $provider): string
    {
        return "nudge:provider-daily:{$provider->id}:".now()->toDateString();
    }
}
