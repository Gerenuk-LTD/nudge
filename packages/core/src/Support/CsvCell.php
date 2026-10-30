<?php

namespace Gerenuk\Marketing\Support;

/**
 * Makes a value safe to open in a spreadsheet: text starting with a formula character is prefixed with an
 * apostrophe, so Excel and Sheets show it instead of running it.
 */
class CsvCell
{
    public static function safe(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
