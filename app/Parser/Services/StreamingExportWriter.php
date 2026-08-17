<?php

namespace App\Parser\Services;

use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StreamingExportWriter
{
    /**
     * @param  Closure(Closure(array<string, mixed>): void): void  $produceRows
     */
    public function write(string $path, string $format, Closure $produceRows): string
    {
        $disk = Storage::disk('local');
        $temporaryPath = $path.'.'.Str::uuid().'.part';
        $directory = dirname($temporaryPath);

        if ($directory !== '.') {
            $disk->makeDirectory($directory);
        }

        $handle = fopen($disk->path($temporaryPath), 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the temporary export file.');
        }

        $headerWritten = false;
        $writeRow = function (array $row) use ($format, $handle, &$headerWritten): void {
            if ($format === 'csv') {
                if (! $headerWritten && fputcsv($handle, array_keys($row)) === false) {
                    throw new RuntimeException('Unable to write the CSV header.');
                }

                $headerWritten = true;
                $values = array_map(
                    fn (mixed $value): mixed => is_bool($value) ? (int) $value : $value,
                    array_values($row),
                );

                if (fputcsv($handle, $values) === false) {
                    throw new RuntimeException('Unable to write a CSV row.');
                }

                return;
            }

            $encoded = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (fwrite($handle, $encoded.PHP_EOL) === false) {
                throw new RuntimeException('Unable to write a JSONL row.');
            }
        };

        try {
            $produceRows($writeRow);

            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush the export file.');
            }

            fclose($handle);
            $handle = null;
            $disk->delete($path);

            if (! $disk->move($temporaryPath, $path)) {
                throw new RuntimeException('Unable to finalize the export file.');
            }
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            $disk->delete($temporaryPath);

            throw $exception;
        }

        return $disk->path($path);
    }
}
