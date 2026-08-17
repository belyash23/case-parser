<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupCommand extends Command
{
    protected $signature = 'database:backup {--keep= : Override backup retention in days}';

    protected $description = 'Create a compressed MariaDB/MySQL backup and prune expired backups.';

    public function handle(): int
    {
        $connectionName = (string) config('database.default');
        $connection = config('database.connections.'.$connectionName, []);
        $driver = (string) ($connection['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error('Database backups support only MySQL and MariaDB connections.');

            return self::FAILURE;
        }

        $database = (string) ($connection['database'] ?? '');
        $username = (string) ($connection['username'] ?? '');

        if ($database === '' || $username === '') {
            $this->error('The database name and username must be configured.');

            return self::FAILURE;
        }

        $directory = trim((string) config('parser.operations.backup_directory', 'backups/database'), '/');
        $path = $directory.'/database-'.now()->format('Ymd-His').'.sql.gz';
        $disk = Storage::disk('local');
        $disk->makeDirectory($directory);
        $handle = gzopen($disk->path($path), 'wb9');

        if ($handle === false) {
            throw new RuntimeException('Unable to create the compressed backup file.');
        }

        $process = new Process(
            $this->dumpCommand($connection, $database, $username),
            timeout: max(60, (int) config('parser.operations.backup_timeout_seconds', 3600)),
        );
        $process->setEnv(['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);
        $errorOutput = '';

        try {
            $process->run(function (string $type, string $buffer) use ($handle, &$errorOutput): void {
                if ($type === Process::OUT) {
                    if (gzwrite($handle, $buffer) === false) {
                        throw new RuntimeException('Unable to write the database backup.');
                    }

                    return;
                }

                $errorOutput .= $buffer;
            });
        } finally {
            gzclose($handle);
        }

        if (! $process->isSuccessful()) {
            $disk->delete($path);
            throw new RuntimeException('Database backup failed: '.Str::limit(trim($errorOutput), 2000));
        }

        if ($disk->size($path) <= 20) {
            $disk->delete($path);
            throw new RuntimeException('Database backup completed without dump contents.');
        }

        if (! chmod($disk->path($path), 0600)) {
            $disk->delete($path);
            throw new RuntimeException('Unable to restrict database backup file permissions.');
        }

        $retentionDays = $this->option('keep');
        $retentionDays = is_numeric($retentionDays)
            ? max(1, (int) $retentionDays)
            : max(1, (int) config('parser.operations.backup_retention_days', 7));
        $deleted = $this->pruneExpiredBackups($directory, $retentionDays);

        $this->info(sprintf(
            'Database backup created: %s (%s). Expired backups deleted: %d.',
            $disk->path($path),
            Number::fileSize($disk->size($path)),
            $deleted,
        ));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $connection */
    private function dumpCommand(array $connection, string $database, string $username): array
    {
        $command = [
            'mariadb-dump',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            '--user='.$username,
        ];
        $socket = (string) ($connection['unix_socket'] ?? '');

        if ($socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '--protocol=tcp';
            $command[] = '--host='.(string) ($connection['host'] ?? '127.0.0.1');
            $command[] = '--port='.(string) ($connection['port'] ?? '3306');
        }

        $command[] = $database;

        return $command;
    }

    private function pruneExpiredBackups(string $directory, int $retentionDays): int
    {
        $disk = Storage::disk('local');
        $threshold = now()->subDays($retentionDays)->getTimestamp();
        $deleted = 0;

        foreach ($disk->files($directory) as $path) {
            if (! str_ends_with($path, '.sql.gz') || $disk->lastModified($path) >= $threshold) {
                continue;
            }

            if ($disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
