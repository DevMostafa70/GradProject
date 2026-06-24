<?php

namespace App\Services;

use App\Models\Interview;
use App\Models\User;  // ← أضف هذا السطر
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    protected LLMService $llmService;

    public function __construct(LLMService $llmService)
    {
        $this->llmService = $llmService;
    }

    /**
     * Get dashboard statistics for a candidate
     */
    public function getStats($user): array  // ← تغيير: إزالة Candidate
    {
        $completedInterviews = $user->interviews()
            ->whereIn('status', ['completed', 'completed_with_report'])
            ->with('finalReport')
            ->get();

        $totalInterviews = $completedInterviews->count();

        // Calculate average score (convert from 0-10 to 0-100)
        $averageScore = null;
        if ($totalInterviews > 0) {
            $avgRaw = $completedInterviews->avg(function ($interview) {
                return $interview->finalReport?->overall_score ?? 0;
            });
            $averageScore = round($avgRaw * 10, 2);
        }

        // Get best score
        $bestScore = null;
        if ($totalInterviews > 0) {
            $bestRaw = $completedInterviews->max(function ($interview) {
                return $interview->finalReport?->overall_score ?? 0;
            });
            $bestScore = round($bestRaw * 10, 2);
        }

        // Get recent interviews (last 3)
        $recentInterviews = $completedInterviews
            ->sortByDesc('created_at')
            ->take(3)
            ->map(function ($interview) {
                return [
                    'id' => $interview->id,
                    'position' => $interview->position,
                    'date' => $interview->completed_at?->format('Y-m-d') ?? $interview->created_at->format('Y-m-d'),
                    'score' => $interview->finalReport
                        ? round($interview->finalReport->overall_score * 10, 2)
                        : null,
                ];
            })
            ->values();

        // Calculate total practice time (sum of duration_seconds from answers)
        $totalPracticeMinutes = (int) $user->answers()
            ->whereHas('interview', function ($query) {
                $query->whereIn('status', ['completed', 'completed_with_report']);
            })
            ->sum('duration_seconds') / 60;

        return [
            'total_interviews' => $totalInterviews,
            'average_score' => $averageScore,
            'best_score' => $bestScore,
            'total_practice_minutes' => round($totalPracticeMinutes),
            'recent_interviews' => $recentInterviews,
        ];
    }

    /**
     * Get progress data for chart (scores over time)
     */
    public function getProgressData($user, string $period = 'month'): array  // ← تغيير: إزالة Candidate
    {
        $query = $user->interviews()
            ->whereIn('status', ['completed', 'completed_with_report'])
            ->with('finalReport')
            ->orderBy('completed_at', 'asc');

        // Apply period filter
        switch ($period) {
            case 'week':
                $query->where('completed_at', '>=', now()->subDays(7));
                break;
            case 'month':
                $query->where('completed_at', '>=', now()->subDays(30));
                break;
            case 'year':
                $query->where('completed_at', '>=', now()->subYear());
                break;
        }

        $interviews = $query->get();

        if ($interviews->isEmpty()) {
            return [
                'labels' => [],
                'scores' => [],
                'trend' => 'neutral',
                'improvement' => 0,
            ];
        }

        $labels = [];
        $scores = [];

        foreach ($interviews as $interview) {
            $score = $interview->finalReport?->overall_score ?? 0;
            $labels[] = $interview->completed_at?->format('M d, Y') ?? $interview->created_at->format('M d, Y');
            $scores[] = round($score * 10, 2);
        }

        // Calculate trend
        $firstScore = $scores[0] ?? 0;
        $lastScore = end($scores);
        $improvement = $lastScore - $firstScore;

        $trend = 'neutral';
        if ($improvement > 5) {
            $trend = 'up';
        } elseif ($improvement < -5) {
            $trend = 'down';
        }

        return [
            'labels' => $labels,
            'scores' => $scores,
            'trend' => $trend,
            'improvement' => round($improvement, 2),
        ];
    }

    /**
     * Get user weaknesses from evaluations
     */
    public function getWeaknesses($user, int $limit = 5): array  // ← تغيير: إزالة Candidate
    {
        // Get all evaluations from completed interviews
        $evaluations = DB::table('evaluations')
            ->join('answers', 'evaluations.answer_id', '=', 'answers.id')
            ->join('interviews', 'answers.interview_id', '=', 'interviews.id')
            ->where('interviews.user_id', $user->id)  // ← تغيير من candidate_id إلى user_id
            ->whereIn('interviews.status', ['completed', 'completed_with_report'])
            ->whereNotNull('evaluations.weaknesses')
            ->select('evaluations.weaknesses', 'evaluations.score')
            ->get();

        if ($evaluations->isEmpty()) {
            return [];
        }

        // Extract weaknesses and count occurrences
        $weaknessCount = [];
        $weaknessScores = [];

        foreach ($evaluations as $evaluation) {
            $weaknesses = json_decode($evaluation->weaknesses, true);
            if (is_array($weaknesses)) {
                foreach ($weaknesses as $weakness) {
                    $key = is_string($weakness) ? $weakness : ($weakness['text'] ?? json_encode($weakness));
                    $weaknessCount[$key] = ($weaknessCount[$key] ?? 0) + 1;
                    $weaknessScores[$key][] = $evaluation->score;
                }
            } elseif (is_string($evaluation->weaknesses)) {
                $weaknessCount[$evaluation->weaknesses] = ($weaknessCount[$evaluation->weaknesses] ?? 0) + 1;
                $weaknessScores[$evaluation->weaknesses][] = $evaluation->score;
            }
        }

        // Sort by frequency and calculate average severity
        $weaknesses = [];
        foreach ($weaknessCount as $weakness => $count) {
            $avgScore = isset($weaknessScores[$weakness])
                ? array_sum($weaknessScores[$weakness]) / count($weaknessScores[$weakness])
                : 5;

            $severity = round((10 - $avgScore) * 10, 2);

            $weaknesses[] = [
                'weakness' => $weakness,
                'occurrences' => $count,
                'avg_score' => round($avgScore * 10, 2),
                'severity' => $severity,
            ];
        }

        // Sort by occurrences (most frequent first)
        usort($weaknesses, function ($a, $b) {
            return $b['occurrences'] - $a['occurrences'];
        });

        return array_slice($weaknesses, 0, $limit);
    }

    /**
     * Generate daily questions based on user weaknesses
     */
    public function getDailyQuestions($user, int $count = 3): array  // ← تغيير: إزالة Candidate
    {
        // Get user weaknesses
        $weaknesses = $this->getWeaknesses($user, 3);

        // Get the position from last interview
        $lastInterview = $user->interviews()
            ->whereIn('status', ['completed', 'completed_with_report'])
            ->orderBy('created_at', 'desc')
            ->first();

        $position = $lastInterview?->position ?? 'Software Developer';
        $skills = $lastInterview?->skills ?? ['general'];

        // تأكد من أن $skills مصفوفة
        if (!is_array($skills)) {
            $skills = ['general'];
        }

        $skillsList = implode(', ', $skills);

        // Build prompt for weakness-based questions
        $weaknessText = '';
        if (!empty($weaknesses)) {
            $weaknessText = "The user struggles with:\n";
            foreach ($weaknesses as $w) {
                // تأكد من أن $w['weakness'] هو نص وليس مصفوفة
                $weakness = is_array($w['weakness']) ? json_encode($w['weakness']) : $w['weakness'];
                $weaknessText .= "- {$weakness}\n";
            }
        } else {
            $weaknessText = "Generate general practice questions for skill improvement.";
        }

        $prompt = <<<EOT
Generate {$count} practice interview questions for a {$position} position.

Required skills: {$skillsList}

{$weaknessText}

Focus the questions on helping the user improve their weak areas.
Make questions challenging but fair.

Format as JSON:
{
    "questions": [
        {
            "question_text": "the question here",
            "type": "technical/behavioral/situational",
            "focus_area": "which weakness this targets"
        }
    ]
}
EOT;

        try {
            $response = $this->llmService->generateQuestionsFromPrompt($prompt, $count);
            return $response;
        } catch (\Exception $e) {
            return $this->getFallbackQuestions($position, $count);
        }
    }

    /**
     * Fallback questions if AI fails
     */
    private function getFallbackQuestions(string $position, int $count): array
    {
        $fallbacks = [
            "Tell me about a challenging project you worked on recently.",
            "How do you handle tight deadlines and pressure?",
            "Describe your experience with team collaboration.",
            "What's your approach to learning new technologies?",
            "How do you debug a complex issue in production?",
        ];

        $questions = [];
        for ($i = 0; $i < $count; $i++) {
            $questions[] = [
                'question_text' => $fallbacks[$i % count($fallbacks)],
                'type' => 'general',
                'focus_area' => 'general',
            ];
        }

        return ['questions' => $questions];
    }
}
