<?php

namespace Gerenuk\Marketing\Imports;

use Generator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Streams a CSV file from storage, so large imports never sit in memory.
 */
class CsvReader
{
    public function __construct(
        private readonly string $disk,
        private readonly string $path,
    ) {}

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        foreach ($this->rawRows() as $row) {
            return array_map(fn (?string $header) => trim((string) $header), $row);
        }

        return [];
    }

    /**
     * Data rows keyed by their 1-based line number in the file, skipping the header and blank lines.
     *
     * @return Generator<int, list<string|null>>
     */
    public function rows(): Generator
    {
        $line = 0;

        foreach ($this->rawRows() as $row) {
            $line++;

            if ($line === 1 || $row === [null] || $row === ['']) {
                continue;
            }

            yield $line => $row;
        }
    }

    public function countRows(): int
    {
        return iterator_count($this->rows());
    }

    /**
     * @return Generator<int, list<string|null>>
     */
    private function rawRows(): Generator
    {
        $stream = Storage::disk($this->disk)->readStream($this->path)
            ?? throw new RuntimeException("Unable to open import file [{$this->path}].");

        try {
            $first = true;

            while (($row = fgetcsv($stream, escape: '')) !== false) {
                if ($first && isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
                    $first = false;
                }

                yield $row;
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
