<?php

namespace Gerenuk\Nudge\Automations;

/**
 * An automation's steps: a list where condition steps hold "yes" and "no" branches of their own.
 * Indexed so any step can find the one that follows it, including leaving a branch.
 *
 * Shape: {steps: [{id, type, config, yes?: [...], no?: [...]}]}
 */
final class AutomationDefinition
{
    /** @var array<string, array{step: array<string, mixed>, next: ?string, yes: ?string, no: ?string}> */
    private array $index = [];

    private ?string $first;

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function __construct(private readonly array $steps)
    {
        $this->first = $this->indexList($steps, null);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function from(array $definition): self
    {
        $steps = $definition['steps'] ?? [];

        return new self(is_array($steps) ? array_values($steps) : []);
    }

    public function first(): ?string
    {
        return $this->first;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function step(?string $id): ?array
    {
        return $id === null ? null : ($this->index[$id]['step'] ?? null);
    }

    public function has(?string $id): bool
    {
        return $id !== null && isset($this->index[$id]);
    }

    /**
     * The step after this one, or null at the end of the automation.
     */
    public function next(string $id): ?string
    {
        return $this->index[$id]['next'] ?? null;
    }

    /**
     * Where a condition step goes: the first step of the chosen branch, or past the condition if the branch is empty.
     */
    public function branch(string $id, bool $matched): ?string
    {
        return $this->index[$id][$matched ? 'yes' : 'no'] ?? null;
    }

    /**
     * Every step, depth first.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(array_map(fn (array $entry) => $entry['step'], $this->index));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ofType(StepType $type): array
    {
        return array_values(array_filter($this->all(), fn (array $step) => ($step['type'] ?? null) === $type->value));
    }

    /**
     * @return array{steps: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['steps' => $this->steps];
    }

    /**
     * @param  array<int, mixed>  $steps
     * @return string|null The first step's ID, or what follows the list when it is empty
     */
    private function indexList(array $steps, ?string $after): ?string
    {
        $steps = array_values(array_filter($steps, fn (mixed $step) => is_array($step) && is_string($step['id'] ?? null)));

        foreach ($steps as $position => $step) {
            $next = $steps[$position + 1]['id'] ?? $after;
            $entry = ['step' => $step, 'next' => $next, 'yes' => null, 'no' => null];

            if (($step['type'] ?? null) === StepType::Condition->value) {
                // Index the branches after the condition itself, so its own entry comes first.
                $this->index[$step['id']] = $entry;
                $entry['yes'] = $this->indexList(is_array($step['yes'] ?? null) ? $step['yes'] : [], $next);
                $entry['no'] = $this->indexList(is_array($step['no'] ?? null) ? $step['no'] : [], $next);
            }

            $this->index[$step['id']] = $entry;
        }

        return $steps[0]['id'] ?? $after;
    }
}
