<?php

namespace Gerenuk\Nudge\Automations;

use Illuminate\Validation\ValidationException;

/**
 * Checks an automation definition's structure on every save. Whether it is complete enough to
 * start is checked separately when it is activated.
 */
class AutomationDefinitionValidator
{
    public const MAX_STEPS = 100;

    public const MAX_DEPTH = 5;

    private const WAIT_UNITS = ['minutes', 'hours', 'days'];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $ids = [];

    /**
     * @throws ValidationException
     */
    public function validate(mixed $definition): void
    {
        $this->errors = [];
        $this->ids = [];

        if (! is_array($definition) || ! is_array($definition['steps'] ?? null) || ! array_is_list($definition['steps'])) {
            throw ValidationException::withMessages(['definition' => __('The automation needs a list of steps.')]);
        }

        $this->validateList($definition['steps'], 'definition.steps', 1);

        if (count($this->ids) > self::MAX_STEPS) {
            $this->errors['definition'] = __('An automation can have at most :max steps.', ['max' => self::MAX_STEPS]);
        }

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }
    }

    /**
     * @param  array<int, mixed>  $steps
     */
    private function validateList(array $steps, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->errors[$path] = __('Conditions can be nested at most :max deep.', ['max' => self::MAX_DEPTH]);

            return;
        }

        foreach ($steps as $index => $step) {
            $stepPath = "{$path}.{$index}";
            $id = is_array($step) ? ($step['id'] ?? null) : null;
            $type = is_array($step) && is_string($step['type'] ?? null) ? StepType::tryFrom($step['type']) : null;

            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || isset($this->ids[$id])) {
                $this->errors[$stepPath] = __('Each step needs its own ID.');

                continue;
            }

            $this->ids[$id] = true;

            if ($type === null) {
                $this->errors[$stepPath] = __('Unknown step type.');

                continue;
            }

            $config = is_array($step['config'] ?? null) ? $step['config'] : [];

            match ($type) {
                StepType::Wait => $this->check(
                    filter_var($config['amount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 525600]]) !== false
                        && in_array($config['unit'] ?? null, self::WAIT_UNITS, true),
                    $stepPath, __('A wait needs a length in minutes, hours or days.'),
                ),
                StepType::Webhook => $this->check(
                    ($config['url'] ?? '') === '' || (is_string($config['url']) && str_starts_with($config['url'], 'https://') && filter_var($config['url'], FILTER_VALIDATE_URL) !== false),
                    $stepPath, __('Webhook URLs must start with https://.'),
                ),
                default => null,
            };

            if ($type === StepType::Condition) {
                foreach (['yes', 'no'] as $branch) {
                    $children = $step[$branch] ?? [];

                    if (! is_array($children) || ! array_is_list($children)) {
                        $this->errors["{$stepPath}.{$branch}"] = __('A condition branch must be a list of steps.');

                        continue;
                    }

                    $this->validateList($children, "{$stepPath}.{$branch}", $depth + 1);
                }
            }
        }
    }

    private function check(bool $passes, string $path, string $message): void
    {
        if (! $passes) {
            $this->errors[$path] = $message;
        }
    }
}
