<?php

namespace Gerenuk\Nudge\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Reads a dashboard table's query string: `search`, `sort` (`column`, or `-column` for descending),
 * `per_page`, and comma-separated filters such as `status=sent,draft`.
 */
class TableQuery
{
    /** @var list<int> */
    public const PAGE_SIZES = [10, 25, 50, 100];

    /**
     * Order by the requested column when it is one of the allowed ones, otherwise by the default.
     * The primary key breaks ties, so pages never overlap.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string|Expression>  $columns  Sort keys mapped to the column or expression they order by.
     * @return Builder<TModel>
     */
    public static function sort(Builder $query, Request $request, array $columns, string $default = '-id'): Builder
    {
        $sort = $request->string('sort')->toString();

        if (! array_key_exists(ltrim($sort, '-'), $columns)) {
            $sort = $default;
        }

        $key = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        return $query
            ->orderBy($columns[$key] ?? $key, $direction)
            ->orderBy($query->getModel()->getQualifiedKeyName(), $direction);
    }

    /**
     * Keep rows where any of the columns contains the term, ignoring case.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<literal-string>  $columns  Column names from code, never from input: they go into the SQL.
     * @return Builder<TModel>
     */
    public static function search(Builder $query, ?string $term, array $columns): Builder
    {
        $term = mb_strtolower(trim((string) $term));

        if ($term === '') {
            return $query;
        }

        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        return $query->where(function (Builder $query) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $query->orWhereRaw("lower({$column}) like ? escape '!'", [$pattern]);
            }
        });
    }

    /**
     * The requested page size when it is one of the allowed ones, otherwise `nudge.tables.per_page`.
     */
    public static function perPage(Request $request): int
    {
        $default = self::defaultPerPage();
        $perPage = $request->integer('per_page', $default);

        return in_array($perPage, self::PAGE_SIZES, true) ? $perPage : $default;
    }

    /**
     * The configured page size, or the smallest allowed one when it is set to something the picker can't show.
     */
    public static function defaultPerPage(): int
    {
        $configured = (int) config('nudge.tables.per_page', self::PAGE_SIZES[0]);

        return in_array($configured, self::PAGE_SIZES, true) ? $configured : self::PAGE_SIZES[0];
    }

    /**
     * The values of a comma-separated filter.
     *
     * @return list<string>
     */
    public static function values(mixed $value): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', is_scalar($value) ? (string) $value : '')),
            fn (string $item): bool => $item !== '',
        ));
    }
}
