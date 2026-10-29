<?php

namespace Gerenuk\Marketing\Support;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Counts per day for the last few days, with the days that had nothing filled in as zero, for small charts.
 */
class DailySeries
{
    /**
     * @param  Builder<Model>|QueryBuilder  $query
     * @param  literal-string  $column  A timestamp column from code, never from input: it goes into the SQL.
     * @return list<array{date: string, count: int}>
     */
    public static function count(Builder|QueryBuilder $query, string $column, int $days = 30): array
    {
        $counts = $query->clone()
            ->where($column, '>=', self::since($days))
            ->selectRaw("date({$column}) as day, count(*) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        return self::fill($counts, $days);
    }

    /**
     * One series per value of a grouping column, such as each list's joins, from a single query.
     *
     * @param  Builder<Model>|QueryBuilder  $query
     * @param  literal-string  $column  A timestamp column from code, never from input: it goes into the SQL.
     * @param  literal-string  $group  The grouping column, also from code.
     * @param  list<int|string>  $keys  Every group to return a series for, including ones with no rows.
     * @return array<int|string, list<array{date: string, count: int}>>
     */
    public static function countBy(Builder|QueryBuilder $query, string $column, string $group, array $keys, int $days = 30): array
    {
        $rows = $query->clone()
            ->where($column, '>=', self::since($days))
            ->selectRaw("{$group} as series, date({$column}) as day, count(*) as total")
            ->groupBy('series', 'day')
            ->get()
            ->groupBy('series');

        return collect($keys)
            ->mapWithKeys(fn (int|string $key) => [$key => self::fill(collect($rows->get($key))->pluck('total', 'day'), $days)])
            ->all();
    }

    private static function since(int $days): CarbonInterface
    {
        return Date::today()->subDays($days - 1);
    }

    /**
     * @param  Collection<array-key, mixed>  $counts  Totals keyed by ISO date.
     * @return list<array{date: string, count: int}>
     */
    private static function fill(Collection $counts, int $days): array
    {
        $since = self::since($days);

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($since, $counts): array {
                $day = $since->copy()->addDays($offset)->toDateString();

                return ['date' => $day, 'count' => (int) ($counts[$day] ?? 0)];
            })
            ->all();
    }
}
