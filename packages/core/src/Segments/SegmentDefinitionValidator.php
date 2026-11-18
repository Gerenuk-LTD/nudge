<?php

namespace Gerenuk\Nudge\Segments;

use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks a segment definition's structure, fields, operators and values.
 *
 * Shape: {match: all|any, conditions: [{type: condition, field, operator, value} | {type: group, match, conditions}]}
 */
class SegmentDefinitionValidator
{
    public const MAX_DEPTH = 3;

    public const MAX_CONDITIONS = 50;

    private int $conditionCount = 0;

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private readonly SegmentFieldCatalog $catalog) {}

    /**
     * @throws ValidationException
     */
    public function validate(mixed $definition, string $attribute = 'definition'): void
    {
        $this->conditionCount = 0;
        $this->errors = [];

        $this->validateGroup($definition, $attribute, 1);

        if ($this->conditionCount > self::MAX_CONDITIONS) {
            $this->errors[$attribute] = __('A segment can have at most :max conditions.', ['max' => self::MAX_CONDITIONS]);
        }

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }
    }

    private function validateGroup(mixed $group, string $path, int $depth): void
    {
        if (! is_array($group) || ! in_array($group['match'] ?? null, ['all', 'any'], true) || ! is_array($group['conditions'] ?? null) || ! array_is_list($group['conditions'])) {
            $this->errors[$path] = __('Each group needs a match type of all or any, and a list of conditions.');

            return;
        }

        if ($depth > self::MAX_DEPTH) {
            $this->errors[$path] = __('Groups can be nested at most :max levels deep.', ['max' => self::MAX_DEPTH]);

            return;
        }

        foreach ($group['conditions'] as $index => $item) {
            $itemPath = "{$path}.conditions.{$index}";

            if (is_array($item) && ($item['type'] ?? null) === 'group') {
                $this->validateGroup($item, $itemPath, $depth + 1);
            } else {
                $this->conditionCount++;
                $this->validateCondition($item, $itemPath);
            }
        }
    }

    private function validateCondition(mixed $condition, string $path): void
    {
        $field = is_array($condition) && is_string($condition['field'] ?? null) ? $this->catalog->find($condition['field']) : null;

        if ($field === null) {
            $this->errors[$path] = __('Choose a field for this condition.');

            return;
        }

        $operator = $condition['operator'] ?? null;

        if (! in_array($operator, $field->type->operators(), true)) {
            $this->errors[$path] = __('Choose a valid comparison for :field.', ['field' => $field->label]);

            return;
        }

        if (FieldType::operatorNeedsValue($operator) && ! $this->valueIsValid($field, $operator, $condition['value'] ?? null)) {
            $this->errors[$path] = __('Enter a valid value for :field.', ['field' => $field->label]);
        }
    }

    private function valueIsValid(SegmentField $field, string $operator, mixed $value): bool
    {
        if (in_array($operator, ['within_last_days', 'more_than_days_ago', 'in_last_days', 'not_in_last_days'], true)) {
            return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 36500]]) !== false;
        }

        return match ($field->type) {
            FieldType::Text => is_string($value) && $value !== '' && mb_strlen($value) <= 255,
            FieldType::Number => is_numeric($value),
            FieldType::Date => is_string($value) && self::isDate($value),
            FieldType::Choice, FieldType::Tag, FieldType::ContactList, FieldType::Campaign => in_array(
                (string) $value,
                array_map(fn (array $option) => (string) $option['value'], $field->options),
                true,
            ),
            FieldType::Boolean => true,
            FieldType::Activity => false,
        };
    }

    private static function isDate(string $value): bool
    {
        try {
            return Date::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
        } catch (Throwable) {
            return false;
        }
    }
}
