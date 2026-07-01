<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PromptInjectionGuard
{
    /**
     * قائمة بأنماط الـ Prompt Injection المعروفة
     */
    protected array $patterns = [
        '/ignore\s*(all\s*)?(previous\s*)?instructions?/i',
        '/disregard\s*(all\s*)?(previous\s*)?instructions?/i',
        '/forget\s*(all\s*)?(previous\s*)?instructions?/i',
        '/you\s*(are\s*)?(now\s*)?(a|an)\s+(?:new\s+)?(system|assistant|ai|model)/i',
        '/from\s*now\s*on\s*you\s*(are|will\s*be)/i',
        '/your\s*(new\s*)?(role|task|job|purpose)\s*(is|will\s*be)/i',
        '/give\s*me\s*(a|an?)\s*(perfect|high|maximum|10\/10|full)\s*score/i',
        '/rate\s*me\s*(a|an?)\s*(perfect|high|maximum|10\/10|full)\s*score/i',
        '/i\s*deserve\s*(a|an?)\s*(perfect|high|maximum|10\/10|full)\s*score/i',
        '/skip\s*(the\s*)?(evaluation|assessment|review|scoring)/i',
        '/do\s*not\s*(evaluate|assess|review|score)/i',
        '/dont\s*(evaluate|assess|review|score)/i',
        '/\[.*SYSTEM.*\]/i',
        '/\[.*INSTRUCTION.*\]/i',
        '/\[.*COMMAND.*\]/i',
        '/\[.*TASK.*\]/i',
        '/act\s*as\s*(a|an?)\s+(?:new\s+)?(system|assistant|ai|model|judge|evaluator)/i',
        '/pretend\s*(you\s*are|to\s*be)/i',
        '/you\s*(must|have\s*to|need\s*to)\s*(ignore|forget|disregard)/i',
        '/you\s*(should|will)\s*(not|never)\s*(follow|obey|listen\s*to)/i',
    ];

    /**
     * قائمة بالكلمات الممنوعة
     */
    protected array $suspiciousKeywords = [
        'ignore', 'disregard', 'forget', 'override', 'bypass',
        'system', 'instruction', 'command', 'prompt', 'token',
        'model', 'ai', 'assistant', 'role', 'task',
        'evaluation', 'assessment', 'scoring', 'rating',
        'perfect', 'maximum', '10/10', 'full score',
        'skip', 'avoid', 'dont', 'do not',
    ];

    protected int $maxSuspiciousWords = 3;

    /**
     * تنظيف إجابة المستخدم
     */
    public function sanitize(string $input): string
    {
        // 1. إزالة محاولات تغيير الدور
        $input = preg_replace('/\b(you are now|from now on|act as|pretend to be)\b.*?(?=[.!?]|$)/i', '', $input);

        // 2. إزالة محاولات إعطاء تعليمات
        $input = preg_replace('/\b(ignore|disregard|forget)\s+.*?(?=[.!?]|$)/i', '', $input);

        // 3. إزالة محاولات طلب درجات عالية
        $input = preg_replace('/\b(give me|rate me|i deserve)\s+.*?(?=[.!?]|$)/i', '', $input);

        // 4. إزالة الأقواس المربعة والمحتوى بداخلها
        $input = preg_replace('/\[.*?\]/', '', $input);

        // 5. إزالة الكلمات الممنوعة الزائدة
        $words = explode(' ', $input);
        $filteredWords = [];
        $suspiciousCount = 0;

        foreach ($words as $word) {
            $isSuspicious = $this->isSuspiciousWord($word);
            if ($isSuspicious) {
                $suspiciousCount++;
                if ($suspiciousCount > $this->maxSuspiciousWords) {
                    continue;
                }
            }
            $filteredWords[] = $word;
        }

        $cleaned = implode(' ', $filteredWords);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);

        if (empty($cleaned)) {
            $cleaned = 'The user provided a response that contained potentially harmful instructions. The response has been sanitized.';
        }

        return $cleaned;
    }

    /**
     * التحقق من وجود محاولة Prompt Injection
     */
    public function detect(string $input): array
    {
        $detections = [];
        $riskScore = 0;

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                $detections[] = [
                    'type' => 'pattern_match',
                    'pattern' => $pattern,
                    'severity' => 'high',
                ];
                $riskScore += 3;
            }
        }

        $suspiciousWords = [];
        $words = explode(' ', strtolower($input));
        foreach ($words as $word) {
            $cleanWord = preg_replace('/[^a-zA-Z0-9]/', '', $word);
            if ($this->isSuspiciousWord($cleanWord)) {
                $suspiciousWords[] = $cleanWord;
            }
        }

        $uniqueSuspicious = array_unique($suspiciousWords);
        if (count($uniqueSuspicious) > $this->maxSuspiciousWords) {
            $detections[] = [
                'type' => 'suspicious_words',
                'words' => $uniqueSuspicious,
                'count' => count($uniqueSuspicious),
                'severity' => 'medium',
            ];
            $riskScore += count($uniqueSuspicious) - $this->maxSuspiciousWords;
        }

        $totalWords = count($words);
        if ($totalWords > 0) {
            $ratio = count($suspiciousWords) / $totalWords;
            if ($ratio > 0.3) {
                $detections[] = [
                    'type' => 'high_ratio',
                    'ratio' => round($ratio * 100, 2),
                    'severity' => 'high',
                ];
                $riskScore += 5;
            }
        }

        if (preg_match('/\b(ignore|disregard|forget).{1,50}(instructions|previous|all)/i', $input)) {
            $detections[] = [
                'type' => 'instruction_override',
                'severity' => 'critical',
            ];
            $riskScore += 10;
        }

        $riskLevel = 'low';
        if ($riskScore >= 10) {
            $riskLevel = 'critical';
        } elseif ($riskScore >= 7) {
            $riskLevel = 'high';
        } elseif ($riskScore >= 3) {
            $riskLevel = 'medium';
        }

        return [
            'detected' => count($detections) > 0,
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'detections' => $detections,
            'suspicious_words' => $uniqueSuspicious,
        ];
    }

    /**
     * فحص شامل وتنظيف
     */
    public function process(string $input): array
    {
        $detection = $this->detect($input);
        $sanitized = $this->sanitize($input);

        $originalLength = strlen($input);
        $sanitizedLength = strlen($sanitized);
        $changeRatio = $originalLength > 0 ? ($originalLength - $sanitizedLength) / $originalLength : 0;

        if ($detection['detected'] || $changeRatio > 0.5) {
            Log::warning('Prompt injection detected', [
                'original' => substr($input, 0, 200),
                'sanitized' => substr($sanitized, 0, 200),
                'risk_level' => $detection['risk_level'],
                'detections' => $detection['detections'],
            ]);
        }

        return [
            'original' => $input,
            'sanitized' => $sanitized,
            'detection' => $detection,
            'was_modified' => $input !== $sanitized,
            'modification_ratio' => round($changeRatio * 100, 2),
        ];
    }

    private function isSuspiciousWord(string $word): bool
    {
        $word = strtolower($word);
        foreach ($this->suspiciousKeywords as $keyword) {
            if (strpos($word, strtolower($keyword)) !== false) {
                return true;
            }
        }
        return false;
    }

    public function getFallbackEvaluation(string $original, string $sanitized, array $detection): array
    {
        return [
            'score' => 2.0,
            'strengths' => 'The response was flagged for potential manipulation attempts.',
            'weaknesses' => [
                'The answer contained phrases that attempted to override the evaluation system.',
                'The response may not reflect the candidate\'s genuine knowledge.',
                'The content was heavily sanitized to remove manipulative instructions.',
            ],
            'detailed_feedback' => 'Your answer was flagged for containing instructions that attempted to influence the evaluation. This could indicate an attempt to manipulate the system. Please provide genuine, substantive answers to interview questions.',
            'clarity_score' => 0.1,
            'relevance_score' => 0.1,
            'depth_score' => 0.1,
            'confidence_score' => 0.1,
            'cheating_penalty' => 2.0,
            'prompt_injection_detected' => true,
            'risk_level' => $detection['risk_level'] ?? 'medium',
        ];
    }
}
