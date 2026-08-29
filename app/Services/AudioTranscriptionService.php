<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use App\Models\Answer;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Exception;

class AudioTranscriptionService
{
    protected string $audioServiceUrl;


    // http://127.0.0.1:5001/analyze
    public function __construct()
    {
        $this->audioServiceUrl = rtrim(
            (string) config('services.audio_service.url'),
            '/'
        );
    }

    public function transcribe($audioFile, ?string $locale = null, ?string $disk = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $preparedFile = null;
        $resource = null;

        try {
            $preparedFile = $this->prepareLocalAudioFile($audioFile, $disk);
            $filePath = $preparedFile['path'];
            $originalName = $preparedFile['name'];
            $fileSize = $preparedFile['size'];
            $maxSize = 25 * 1024 * 1024;

            if ($fileSize > $maxSize) {
                Log::warning('Audio file too large for Whisper API');

                return [
                    'success' => false,
                    'transcript' => 'Transcription failed',
                    'error' => 'Audio file exceeds 25MB limit',
                    'confidence' => 0,
                    'language' => $locale,
                    'duration' => null,
                    'word_count' => 0,
                ];
            }

            Log::info('Starting Whisper transcription', [
                'file' => $originalName,
                'size' => $fileSize,
            ]);

            $resource = fopen($filePath, 'rb');

            if ($resource === false) {
                throw new Exception("Unable to open audio file: {$originalName}");
            }

            $response = OpenAI::audio()->transcribe([
                'model' => 'whisper-1',
                'file' => $resource,
                'response_format' => 'verbose_json',
                'language' => $locale,
                'temperature' => 0.2,
            ]);

            $transcript = $response->text ?? '';
            $language = $response->language ?? $locale;
            $duration = $response->duration ?? null;
            $wordCount = $this->countWords($transcript);

            $confidence = 0.95;

            if (isset($response->segments) && count($response->segments) > 0) {
                $totalConfidence = 0;

                foreach ($response->segments as $segment) {
                    $totalConfidence += $segment->confidence ?? 0.95;
                }

                $confidence = $totalConfidence / count($response->segments);
            }

            Log::info('Whisper transcription completed', [
                'file' => $originalName,
                'word_count' => $wordCount,
                'duration' => $duration,
                'confidence' => $confidence,
            ]);

            return [
                'success' => true,
                'transcript' => $transcript,
                'confidence' => $confidence,
                'language' => $language,
                'duration' => $duration,
                'word_count' => $wordCount,
                'segments' => $response->segments ?? [],
                'raw_response' => method_exists($response, 'toArray') ? $response->toArray() : [],
            ];

        } catch (Exception $e) {
            Log::error('Whisper transcription failed', [
                'error' => $e->getMessage(),
                'file' => $originalName ?? 'unknown',
            ]);

            return [
                'success' => false,
                'transcript' => 'Transcription failed',
                'error' => $e->getMessage(),
                'confidence' => 0,
                'language' => $locale,
                'duration' => null,
                'word_count' => 0,
            ];
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }

            $this->cleanupPreparedAudioFile($preparedFile);
        }
    }

    public function transcribeWithGPT4oMini($audioFile, ?string $locale = null, ?string $disk = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $preparedFile = null;
        $resource = null;
        $fallback = false;

        try {
            $preparedFile = $this->prepareLocalAudioFile($audioFile, $disk);
            $filePath = $preparedFile['path'];
            $originalName = $preparedFile['name'];
            $fileSize = $preparedFile['size'];
            $maxSize = 25 * 1024 * 1024;

            if ($fileSize > $maxSize) {
                return [
                    'success' => false,
                    'transcript' => 'Transcription failed',
                    'error' => 'Audio file exceeds 25MB limit',
                    'confidence' => 0,
                ];
            }

            Log::info('Starting GPT-4o-mini transcription', [
                'file' => $originalName,
            ]);

            $resource = fopen($filePath, 'rb');

            if ($resource === false) {
                throw new Exception("Unable to open audio file: {$originalName}");
            }

            $response = OpenAI::audio()->transcribe([
                'model' => 'gpt-4o-mini-transcribe',
                'file' => $resource,
                'response_format' => 'verbose_json',
                'language' => $locale,
                'prompt' => $locale === 'ar'
                    ? 'هذه إجابة في مقابلة عمل. اكتب الكلام المنطوق بدقة كما هو باللغة العربية، مع الحفاظ على المصطلحات التقنية.'
                    : 'This is a job interview answer. Transcribe the spoken English accurately and preserve technical terms.',
            ]);

            $transcript = $response->text ?? '';
            $wordCount = $this->countWords($transcript);

            return [
                'success' => true,
                'transcript' => $transcript,
                'confidence' => 0.96,
                'language' => $response->language ?? $locale,
                'duration' => $response->duration ?? null,
                'word_count' => $wordCount,
                'model_used' => 'gpt-4o-mini-transcribe',
            ];

        } catch (Exception $e) {
            Log::error('GPT-4o-mini transcription failed', [
                'error' => $e->getMessage(),
                'fallback' => 'using whisper-1',
            ]);

            $fallback = true;
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }

            $this->cleanupPreparedAudioFile($preparedFile);
        }

        if ($fallback) {
            return $this->transcribe($audioFile, $locale, $disk);
        }

        throw new Exception('Audio transcription failed without a fallback result.');
    }

    public function transcribeAuto($audioFile, ?string $locale = null, ?string $disk = null): array
    {
        return $this->transcribeWithGPT4oMini($audioFile, $locale, $disk);
    }

    /**
     * Analyze audio using Python Librosa microservice.
     */
    public function analyzeAudio($audioFile, Answer $answer): array
    {
        $preparedFile = null;
        $resource = null;

        try {
            $preparedFile = $this->prepareLocalAudioFile(
                $audioFile,
                $answer->audioStorageDisk()
            );
            $filePath = $preparedFile['path'];
            $resource = fopen($filePath, 'rb');

            if ($resource === false) {
                throw new Exception("Unable to open audio file: {$preparedFile['name']}");
            }

            $response = Http::timeout(120)
                ->attach(
                    'audio_file',
                    $resource,
                    $preparedFile['name']
                )
                ->post($this->audioServiceUrl . '/analyze');

            if (!$response->successful()) {
                Log::warning('Python audio service failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $this->fallbackAudioAnalysis($answer);
            }

            $data = $response->json('data');

            Log::info('Audio analysis completed from Python service', [
                'confidence_score' => $data['confidence_score'] ?? null,
                'silence_ratio' => $data['silence']['silence_ratio'] ?? null,
                'duration' => $data['duration'] ?? null,
            ]);

            $transcript = $answer->transcription ?? '';
            $wordCount = $this->countWords($transcript);
            $uniqueWordCount = $this->countUniqueWords($transcript);

            return [
                'speaking_rate' => $data['speech_rate']['estimated_speech_rate_wpm'] ?? 0,
                'filler_word_count' => 0,
                'filler_words_found' => [],
                'voice_stability' => $data['pitch']['pitch_stability'] ?? null,
                'pauses_percentage' => isset($data['silence']['silence_ratio'])
                    ? round($data['silence']['silence_ratio'] * 100, 2)
                    : null,
                'sentiment_scores' => null,
                'confidence_level' => $data['confidence_score'] ?? null,
                'hesitation_score' => $data['silence']['short_pause_ratio'] ?? null,
                'clarity_score' => isset($data['silence']['silence_ratio'])
                    ? round(1 - $data['silence']['silence_ratio'], 3)
                    : null,
                'word_count' => $wordCount,
                'unique_word_count' => $uniqueWordCount,
                'full_analysis_data' => $data,
            ];

        } catch (Exception $e) {
            Log::error('Audio analysis failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackAudioAnalysis($answer);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }

            $this->cleanupPreparedAudioFile($preparedFile);
        }
    }

    /**
     * Prepare uploaded or remotely stored audio for libraries that require a local path.
     *
     * @return array{path: string, name: string, size: int, temporary: bool}
     */
    private function prepareLocalAudioFile($audioFile, ?string $disk = null): array
    {
        if ($audioFile instanceof UploadedFile) {
            $path = $audioFile->getRealPath();

            if ($path === false || !is_file($path)) {
                throw new Exception('Uploaded audio temporary file is unavailable.');
            }

            $size = $audioFile->getSize();

            return [
                'path' => $path,
                'name' => $audioFile->getClientOriginalName(),
                'size' => $size === false ? (int) filesize($path) : (int) $size,
                'temporary' => false,
            ];
        }

        if (!is_string($audioFile) || $audioFile === '') {
            throw new Exception('Stored audio path is invalid.');
        }

        $diskName = $disk ?: $this->configuredAudioDisk();
        $storage = Storage::disk($diskName);

        if (!$storage->exists($audioFile)) {
            throw new Exception("Audio file not found on disk [{$diskName}]: {$audioFile}");
        }

        $source = $storage->readStream($audioFile);

        if ($source === false) {
            throw new Exception("Unable to read audio file from disk [{$diskName}]: {$audioFile}");
        }

        $basePath = tempnam(sys_get_temp_dir(), 'interview_audio_');

        if ($basePath === false) {
            fclose($source);
            throw new Exception('Unable to create a temporary audio file.');
        }

        $temporaryPath = $basePath;
        $extension = strtolower((string) pathinfo($audioFile, PATHINFO_EXTENSION));

        if ($extension !== '' && preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1) {
            $temporaryPath = $basePath . '.' . $extension;

            if (!rename($basePath, $temporaryPath)) {
                fclose($source);
                @unlink($basePath);
                throw new Exception('Unable to prepare the temporary audio filename.');
            }
        }

        $destination = fopen($temporaryPath, 'wb');

        if ($destination === false) {
            fclose($source);
            @unlink($temporaryPath);
            throw new Exception('Unable to open the temporary audio file for writing.');
        }

        $copyException = null;

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new Exception('Unable to copy the stored audio into a temporary file.');
            }
        } catch (\Throwable $e) {
            $copyException = $e;
        } finally {
            fclose($source);
            fclose($destination);
        }

        if ($copyException !== null) {
            @unlink($temporaryPath);
            throw $copyException;
        }

        $size = filesize($temporaryPath);

        if ($size === false) {
            @unlink($temporaryPath);
            throw new Exception('Unable to determine the temporary audio file size.');
        }

        return [
            'path' => $temporaryPath,
            'name' => basename($audioFile),
            'size' => (int) $size,
            'temporary' => true,
        ];
    }

    private function cleanupPreparedAudioFile(?array $preparedFile): void
    {
        if (($preparedFile['temporary'] ?? false) && is_file($preparedFile['path'] ?? '')) {
            @unlink($preparedFile['path']);
        }
    }

    private function configuredAudioDisk(): string
    {
        return (string) config(
            'interview_ai.audio.storage_disk',
            config('uploads.audio_disk', 'public')
        );
    }

    private function fallbackAudioAnalysis(Answer $answer): array
    {
        $duration = $answer->duration_seconds ?? 0;
        $transcript = $answer->transcription ?? '';
        $wordCount = $this->countWords($transcript);

        $speakingRate = $duration > 0
            ? ($wordCount / $duration) * 60
            : 0;

        return [
            'speaking_rate' => round($speakingRate, 2),
            'filler_word_count' => 0,
            'filler_words_found' => [],
            'voice_stability' => null,
            'pauses_percentage' => null,
            'sentiment_scores' => null,
            'confidence_level' => null,
            'hesitation_score' => null,
            'clarity_score' => null,
            'word_count' => $wordCount,
            'unique_word_count' => $this->countUniqueWords($transcript),
            'full_analysis_data' => [
                'fallback' => true,
                'reason' => 'Python audio service unavailable or failed',
            ],
        ];
    }
    private function normalizeLocale(?string $locale): string
    {
        $locale = strtolower(substr((string) ($locale ?: app()->getLocale()), 0, 2));

        return $locale === 'ar' ? 'ar' : 'en';
    }

    private function countWords(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function countUniqueWords(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return count(array_unique($matches[0] ?? []));
    }

}
