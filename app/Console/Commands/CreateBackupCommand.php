<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class CreateBackupCommand extends Command
{
    protected $signature = 'backup:create
                            {--type=manual : Type of backup (manual, scheduled)}
                            {--admin-id= : Admin ID who initiated the backup}
                            {--tables= : Comma-separated list of tables to backup}
                            {--exclude-tables= : Comma-separated list of tables to exclude}';

    protected $description = 'Create a database backup';

    protected array $excludedTables = [
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'password_reset_tokens', 'notifications'
    ];

    public function handle(): int
    {
        $backupPath = null;
        $zipPath = null;
        $storedPath = null;
        $backupRecordCreated = false;
        $backupDisk = (string) config('uploads.backup_disk', 'local');

        $this->info('🔄 Starting database backup...');

        try {
            $type = $this->option('type');
            $adminId = $this->option('admin-id');
            $tables = $this->option('tables');
            $excludeTables = $this->option('exclude-tables');

            // Get database connection
            $connection = config('database.default');
            $database = config("database.connections.{$connection}.database");
            $username = config("database.connections.{$connection}.username");
            $password = config("database.connections.{$connection}.password");
            $host = config("database.connections.{$connection}.host");
            $port = config("database.connections.{$connection}.port") ?? 3306;

            // Generate filename
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "backup_{$timestamp}.sql";
            $zipFilename = "backup_{$timestamp}.zip";

            $temporaryDirectory = storage_path('app/backups/tmp');
            $temporaryPrefix = Str::uuid()->toString();
            $backupPath = $temporaryDirectory . DIRECTORY_SEPARATOR . "{$temporaryPrefix}_{$filename}";
            $zipPath = $temporaryDirectory . DIRECTORY_SEPARATOR . "{$temporaryPrefix}_{$zipFilename}";

            // Create backup directory if not exists
            if (!is_dir($temporaryDirectory)) {
                mkdir($temporaryDirectory, 0755, true);
            }

            // Build mysqldump command
            $tablesList = '';
            if ($tables) {
                $tablesList = '--tables=' . str_replace(',', ' ', $tables);
            }

            $exclude = [];
            if ($excludeTables) {
                $exclude = explode(',', $excludeTables);
            }
            $exclude = array_merge($exclude, $this->excludedTables);

            $excludeString = '';
            foreach ($exclude as $table) {
                $excludeString .= " --ignore-table={$database}.{$table}";
            }

            $command = sprintf(
                'mysqldump --host=%s --port=%s --user=%s --password=%s %s %s --single-transaction --quick --no-autocommit --skip-lock-tables --no-tablespaces %s > %s',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($password),
                $database,
                $tablesList,
                $excludeString,
                escapeshellarg($backupPath)
            );

            $this->info('📤 Executing mysqldump...');

            // Execute dump
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception('mysqldump failed: ' . $process->getErrorOutput());
            }

            $this->info('✅ mysqldump completed successfully');

            // Compress the file
            $this->info('📦 Compressing backup...');

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
                throw new \Exception('Failed to create ZIP file');
            }

            $zip->addFile($backupPath, $filename);
            $zip->close();

            $fileSize = filesize($zipPath);

            if ($fileSize === false) {
                throw new \Exception('Failed to determine backup ZIP size');
            }

            $storedPath = "backups/{$zipFilename}";
            $zipStream = fopen($zipPath, 'rb');

            if ($zipStream === false) {
                throw new \Exception('Failed to open backup ZIP for upload');
            }

            try {
                $uploaded = Storage::disk($backupDisk)->writeStream(
                    $storedPath,
                    $zipStream,
                    ['visibility' => 'private']
                );
            } finally {
                fclose($zipStream);
            }

            if (!$uploaded) {
                throw new \Exception("Failed to upload backup ZIP to disk [{$backupDisk}]");
            }

            // Create backup record
            $backup = Backup::create([
                'filename' => $zipFilename,
                'file_path' => $storedPath,
                'size' => $fileSize,
                'status' => Backup::STATUS_COMPLETED,
                'type' => $type,
                'completed_at' => now(),
                'created_by' => $adminId ? (int)$adminId : null,
                'metadata' => [
                    'tables' => $tables ? explode(',', $tables) : null,
                    'excluded_tables' => $exclude,
                    'database' => $database,
                    'host' => $host,
                    'compressed' => true,
                    'original_filename' => $filename,
                ],
            ]);
            $backupRecordCreated = true;

            $this->info('✅ Backup created successfully!');
            $this->info("📁 File: {$zipFilename}");
            $this->info("📦 Size: " . $backup->file_size);
            $this->info("🆔 Backup ID: {$backup->id}");

            Log::info('Backup created successfully', [
                'backup_id' => $backup->id,
                'filename' => $zipFilename,
                'size' => $fileSize,
                'type' => $type,
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            if ($storedPath !== null && !$backupRecordCreated) {
                Storage::disk($backupDisk)->delete($storedPath);
            }

            $this->error('❌ Backup failed: ' . $e->getMessage());

            Log::error('Backup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        } finally {
            foreach ([$backupPath, $zipPath] as $temporaryFile) {
                if (is_string($temporaryFile) && is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
        }
    }
}
