<?php

namespace Gerenuk\Nudge\Segments;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * A raw SQL predicate about one column. The template is fixed SQL with a `:column`
 * placeholder, which the query grammar fills with the safely wrapped column; values are bound.
 */
final class ColumnPredicate implements Expression
{
    /**
     * @param  literal-string  $template  e.g. "lower(:column) like ? escape '!'"
     */
    public function __construct(
        private readonly string $column,
        private readonly string $template,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        return str_replace(':column', $grammar->wrap($this->column), $this->template);
    }
}
