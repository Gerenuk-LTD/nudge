<?php

namespace Gerenuk\Marketing\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Change detection that ignores object key order in JSON attributes. MySQL JSON columns store keys in their own
 * order, so Eloquent's isDirty() reports an unchanged array as changed after a round trip.
 */
class JsonChanges
{
    /**
     * @param  list<string>  $attributes
     */
    public static function dirty(Model $model, array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            if (! $model->isDirty($attribute)) {
                continue;
            }

            $before = $model->getOriginal($attribute);
            $after = $model->getAttribute($attribute);

            if (! is_array($before) || ! is_array($after) || self::sorted($before) !== self::sorted($after)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        ksort($value);

        return array_map(fn ($item) => is_array($item) ? self::sorted($item) : $item, $value);
    }
}
