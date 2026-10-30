<?php

namespace Gerenuk\Marketing\Segments;

enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Choice = 'choice';
    case Tag = 'tag';
    case ContactList = 'list';
    case Activity = 'activity';

    /**
     * @return list<string>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Text => ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_empty', 'is_not_empty'],
            self::Number => ['equals', 'not_equals', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'],
            self::Date => ['before', 'after', 'within_last_days', 'more_than_days_ago', 'is_empty', 'is_not_empty'],
            self::Boolean => ['is_true', 'is_false'],
            self::Choice => ['is', 'is_not'],
            self::Tag => ['has', 'has_not'],
            self::ContactList => ['in', 'not_in'],
            self::Activity => ['in_last_days', 'not_in_last_days', 'never'],
        };
    }

    public static function operatorNeedsValue(string $operator): bool
    {
        return ! in_array($operator, ['is_empty', 'is_not_empty', 'is_true', 'is_false', 'never'], true);
    }
}
