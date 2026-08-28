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

    public function transcribe($audioFile, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);

        try {
            if ($audioFile instanceof UploadedFile) {
                $filePath = $audioFile->getRealPath();
                $originalName = $audioFile->getClientOriginalName();
            } else {
                $filePath = Storage::disk('public')->path($audioFile);
                $originalName = basename($audioFile);
            }

            if (!file_exists($filePath)) {
                throw new Exception("Audio file not found at path: {$filePath}");
            }

            $fileSize = filesize($filePath);
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

            $resource = fopen($filePath, 'r');

            $response = OpenAI::audio()->transcribe([
                'model' => 'whisper-1',
                'file' => $resource,
                'response_format' => 'verbose_json',
                'language' => $locale,
                'temperature' => 0.2,
            ]);

            if (is_resource($resource)) {
                fclose($resource);
            }

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
            if (isset($resource) && is_resource($resource)) {
                fclose($resource);
            }

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
        }
    }

    public function transcribeWithGPT4oMini($audioFile, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);

        try {
            if ($audioFile instanceof UploadedFile) {
                $filePath = $audioFile->getRealPath();
                $originalName = $audioFile->getClientOriginalName();
            } else {
                $filePath = Storage::disk('public')->path($audioFile);
                $originalName = basename($audioFile);
            }

            if (!file_exists($filePath)) {
                throw new Exception("Audio file not found at path: {$filePath}");
            }

            $fileSize = filesize($filePath);
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

            $resource = fopen($filePath, 'r');

            $response = OpenAI::audio()->transcribe([
                'model' => 'gpt-4o-mini-transcribe',
                'file' => $resource,
                'response_format' => 'verbose_json',
                'language' => $locale,
                'prompt' => $locale === 'ar'
                    ? 'هذه إجابة في مقابلة عمل. اكتب الكلام المنطوق بدقة كما هو باللغة العربية، مع الحفاظ على المصطلحات التقنية.'
                    : 'This is a job interview answer. Transcribe the spoken English accurately and preserve technical terms.',
            ]);

            if (is_resource($resource)) {
                fclose($resource);
            }

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

            return $this->transcribe($audioFile, $locale);
        }
    }

    public function transcribeAuto($audioFile, ?string $locale = null): array
    {
        return $this->transcribeWithGPT4oMini($audioFile, $locale);
    }

    /**
     * Analyze audio using Python Librosa microservice.
     */
    public function analyzeAudio($audioFile, Answer $answer): array
    {
        try {
            if ($audioFile instanceof UploadedFile) {
                $filePath = $audioFile->getRealPath();
            } else {
                $filePath = Storage::disk('public')->path($audioFile);
            }

            if (!file_exists($filePath)) {
                throw new Exception("Audio file not found at path: {$filePath}");
            }

            $response = Http::timeout(120)
                ->attach(
                    'audio_file',
                    fopen($filePath, 'r'),
                    basename($filePath)
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
        }
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
