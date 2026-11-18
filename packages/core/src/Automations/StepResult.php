<?php

namespace Gerenuk\Marketing\Automations;

use Carbon\CarbonInterface;

/**
 * What a step tells the run to do next.
 */
final class StepResult
{
    /**
     * @param  array<string, mixed>  $notes  Kept on the run's context, for example a webhook's response status
     */
    private function __construct(
        public readonly string $action,
        public readonly ?bool $matched = null,
        public readonly ?CarbonInterface $until = null,
        public readonly ?string $reason = null,
        public readonly array $notes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $notes
     */
    public static function next(array $notes = []): self
    {
        return new self('next', notes: $notes);
    }

    public static function branch(bool $matched): self
    {
        return new self('branch', matched: $matched);
    }

    /** Move past this step, then wait. */
    public static function waitUntil(CarbonInterface $until): self
    {
        return new self('wait', until: $until);
    }

    /** Stay on this step and try it again later. */
    public static function retryAt(CarbonInterface $until): self
    {
        return new self('retry', until: $until);
    }

    public static function exit(string $reason): self
    {
        return new self('exit', reason: $reason);
    }
}
