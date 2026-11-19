<?php

namespace Gerenuk\Nudge\Segments;

use Carbon\CarbonInterface;
use Gerenuk\Nudge\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Turns a validated segment definition into constraints on a contact query.
 */
class SegmentQuery
{
    public function __construct(private readonly SegmentFieldCatalog $catalog) {}

    /**
     * Matching active contacts for a definition.
     *
     * @param  array<string, mixed>  $definition
     * @return Builder<Contact>
     */
    public function contacts(array $definition): Builder
    {
        return $this->apply(Contact::query()->active(), $definition);
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $definition
     * @return Builder<Contact>
     */
    public function apply(Builder $query, array $definition): Builder
    {
        if ($definition['conditions'] === []) {
            return $query;
        }

        return $query->where(fn (Builder $nested) => $this->applyGroup($nested, $definition));
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $group
     */
    private function applyGroup(Builder $query, array $group): void
    {
        $boolean = $group['match'] === 'any' ? 'or' : 'and';

        foreach ($group['conditions'] as $item) {
            if (($item['type'] ?? null) === 'group') {
                if ($item['conditions'] !== []) {
                    $query->where(fn (Builder $nested) => $this->applyGroup($nested, $item), boolean: $boolean);
                }

                continue;
            }

            $query->where(fn (Builder $nested) => $this->applyCondition($nested, $item), boolean: $boolean);
        }
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array{field: string, operator: string, value?: mixed}  $condition
     */
    private function applyCondition(Builder $query, array $condition): void
    {
        $field = $this->catalog->find($condition['field']);
        $operator = $condition['operator'];
        $value = $condition['value'] ?? null;

        match ($field->type) {
            FieldType::Tag => $operator === 'has'
                ? $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($value))
                : $query->whereDoesntHave('tags', fn (Builder $tags) => $tags->whereKey($value)),
            FieldType::ContactList => $operator === 'in'
                ? $query->whereHas('lists', fn (Builder $lists) => $lists->whereKey($value))
                : $query->whereDoesntHave('lists', fn (Builder $lists) => $lists->whereKey($value)),
            FieldType::Activity => $this->applyActivity($query, $field->column, $operator, $value),
            FieldType::Campaign => $this->applyCampaign($query, $operator, $value),
            FieldType::Text => $this->applyText($query, $field->column, $operator, (string) $value),
            FieldType::Number => $this->applyNumber($query, $field->column, $operator, $value),
            FieldType::Date => $this->applyDate($query, $field->column, $operator, $value),
            FieldType::Boolean => $operator === 'is_true'
                ? $query->where($field->column, true)
                : $query->where(fn (Builder $q) => $q->where($field->column, false)->orWhereNull($field->column)),
            FieldType::Choice => $operator === 'is'
                ? $query->where($field->column, $value)
                : $query->where($field->column, '!=', $value),
        };
    }

    /**
     * Text comparisons are case-insensitive on every database, including JSON values, which MySQL compares case-sensitively.
     *
     * @param  Builder<Contact>  $query
     */
    private function applyText(Builder $query, string $column, string $operator, string $value): void
    {
        // '!' is used as the LIKE escape character because SQLite has no default and MySQL treats backslashes specially.
        $lowered = mb_strtolower($value);
        $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $lowered);

        match ($operator) {
            'equals' => $query->whereRaw($this->predicate($query, $column, 'lower(:column) = ?'), [$lowered]),
            'not_equals' => $query->where(fn (Builder $q) => $q->whereRaw($this->predicate($query, $column, 'lower(:column) != ?'), [$lowered])->orWhereNull($column)),
            'contains' => $query->whereRaw($this->predicate($query, $column, "lower(:column) like ? escape '!'"), ["%{$needle}%"]),
            'not_contains' => $query->where(fn (Builder $q) => $q->whereRaw($this->predicate($query, $column, "lower(:column) not like ? escape '!'"), ["%{$needle}%"])->orWhereNull($column)),
            'starts_with' => $query->whereRaw($this->predicate($query, $column, "lower(:column) like ? escape '!'"), ["{$needle}%"]),
            'ends_with' => $query->whereRaw($this->predicate($query, $column, "lower(:column) like ? escape '!'"), ["%{$needle}"]),
            'is_empty' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '')),
            'is_not_empty' => $query->whereNotNull($column)->where($column, '!=', ''),
            default => throw new InvalidArgumentException("Unknown text operator [{$operator}]."),
        };
    }

    /**
     * Numbers are cast so JSON values compare numerically rather than as text.
     *
     * @param  Builder<Contact>  $query
     */
    private function applyNumber(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'equals' => $query->whereRaw($this->predicate($query, $column, 'CAST(:column AS DECIMAL(30, 10)) = ?'), [$value]),
            'not_equals' => $query->where(fn (Builder $q) => $q->whereRaw($this->predicate($query, $column, 'CAST(:column AS DECIMAL(30, 10)) != ?'), [$value])->orWhereNull($column)),
            'greater_than' => $query->whereRaw($this->predicate($query, $column, 'CAST(:column AS DECIMAL(30, 10)) > ?'), [$value]),
            'less_than' => $query->whereRaw($this->predicate($query, $column, 'CAST(:column AS DECIMAL(30, 10)) < ?'), [$value]),
            'is_empty' => $query->whereNull($column),
            'is_not_empty' => $query->whereNotNull($column),
            default => throw new InvalidArgumentException("Unknown number operator [{$operator}]."),
        };
    }

    /**
     * Built-in date columns are timestamps; custom date fields are Y-m-d strings. Both are compared by whole day.
     *
     * @param  Builder<Contact>  $query
     */
    private function applyDate(Builder $query, string $column, string $operator, mixed $value): void
    {
        $isTimestamp = ! str_contains($column, '->');
        $startOfDay = fn (CarbonInterface $day): string => $isTimestamp ? $day->startOfDay()->toDateTimeString() : $day->toDateString();

        match ($operator) {
            'before' => $query->where($column, '<', $startOfDay(Date::parse($value))),
            'after' => $isTimestamp
                ? $query->where($column, '>=', $startOfDay(Date::parse($value)->addDay()))
                : $query->where($column, '>', $startOfDay(Date::parse($value))),
            'within_last_days' => $query->where($column, '>=', $startOfDay(now()->subDays((int) $value))),
            'more_than_days_ago' => $query->where($column, '<', $startOfDay(now()->subDays((int) $value))),
            'is_empty' => $query->whereNull($column),
            'is_not_empty' => $query->whereNotNull($column),
            default => throw new InvalidArgumentException("Unknown date operator [{$operator}]."),
        };
    }

    /**
     * Whether the contact did something across all their campaign emails: an open, a click or a send, by whole day.
     *
     * @param  Builder<Contact>  $query
     */
    private function applyActivity(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'in_last_days' => $query->whereHas('campaignRecipients', fn (Builder $recipients) => $recipients->where($column, '>=', now()->subDays((int) $value)->startOfDay())),
            'not_in_last_days' => $query->whereDoesntHave('campaignRecipients', fn (Builder $recipients) => $recipients->where($column, '>=', now()->subDays((int) $value)->startOfDay())),
            'never' => $query->whereDoesntHave('campaignRecipients', fn (Builder $recipients) => $recipients->whereNotNull($column)),
            default => throw new InvalidArgumentException("Unknown activity operator [{$operator}]."),
        };
    }

    /**
     * Whether the contact received, opened or clicked one campaign. The "not_" forms also match people the campaign
     * never reached, so "didn't open" needs pairing with "received" to find who missed it.
     *
     * @param  Builder<Contact>  $query
     */
    private function applyCampaign(Builder $query, string $operator, mixed $value): void
    {
        $column = match ($operator) {
            'received', 'not_received' => 'sent_at',
            'opened', 'not_opened' => 'opened_at',
            'clicked', 'not_clicked' => 'clicked_at',
            default => throw new InvalidArgumentException("Unknown campaign operator [{$operator}]."),
        };
        $did = fn (Builder $recipients) => $recipients->where('campaign_id', $value)->whereNotNull($column);

        str_starts_with($operator, 'not_')
            ? $query->whereDoesntHave('campaignRecipients', $did)
            : $query->whereHas('campaignRecipients', $did);
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  literal-string  $template
     */
    private function predicate(Builder $query, string $column, string $template): ColumnPredicate
    {
        return new ColumnPredicate($query->qualifyColumn($column), $template);
    }
}
