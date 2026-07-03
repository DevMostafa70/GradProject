<?php

namespace App\Console\Commands;

use App\Models\Answer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupAudioFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audio:cleanup
                            {--dry-run : Simulate deletion without actually deleting files}
                            {--force : Force deletion even if retention is disabled}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete old audio files for privacy and storage optimization';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧹 Starting audio files cleanup...');

        $retentionDays = Answer::getRetentionDays();
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');

        // Check retention settings
        if ($retentionDays === null && !$force) {
            $this->warn('⚠️ Audio retention is disabled (retention_days = null).');
            $this->warn('Use --force to override this setting.');
            return Command::FAILURE;
        }

        if ($retentionDays === 0 || $force) {
            $this->info('🔴 Retention policy: Delete immediately after processing');
        } else {
            $this->info("📅 Retention policy: Delete after {$retentionDays} days");
        }

        if ($isDryRun) {
            $this->info('🧪 DRY RUN MODE - No files will be deleted');
        }

        // Get answers eligible for cleanup
        $query = Answer::shouldDeleteAudio();

        if ($force && $retentionDays === null) {
            // Force deletion for all processed answers
            $query = Answer::where('status', Answer::STATUS_EVALUATED)
                ->whereNull('audio_deleted_at')
                ->whereNotNull('audio_file_path');
        }

        $answers = $query->get();
        $count = $answers->count();

        $this->info("📊 Found {$count} answers with audio files eligible for deletion");

        if ($count === 0) {
            $this->info('✅ No audio files to clean up.');
            return Command::SUCCESS;
        }

        // Show details before proceeding
        if ($this->output->isVerbose()) {
            $this->table(
                ['Answer ID', 'Interview ID', 'File Path', 'Processed At'],
                $answers->map(function ($answer) {
                    return [
                        $answer->id,
                        $answer->interview_id,
                        $answer->audio_file_path,
                        $answer->processed_at?->toDateString(),
                    ];
                })->toArray()
            );
        }

        if (!$isDryRun) {
            $bar = $this->output->createProgressBar($count);
            $deletedCount = 0;
            $failedCount = 0;

            foreach ($answers as $answer) {
                try {
                    if ($answer->deleteAudioFile()) {
                        $deletedCount++;
                    } else {
                        $failedCount++;
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    Log::error('Failed to delete audio file', [
                        'answer_id' => $answer->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            $this->info("✅ Cleanup completed:");
            $this->info("   - Deleted: {$deletedCount} files");
            $this->info("   - Failed: {$failedCount} files");

            Log::info('Audio cleanup command completed', [
                'deleted' => $deletedCount,
                'failed' => $failedCount,
            ]);

            return Command::SUCCESS;
        } else {
            // Dry run - just show what would be deleted
            $this->info('🧪 DRY RUN - Would delete the following files:');
            foreach ($answers as $answer) {
                $this->line("   - Answer #{$answer->id}: {$answer->audio_file_path}");
            }

            $this->info("Total files that would be deleted: {$count}");
            return Command::SUCCESS;
        }
    }
}
