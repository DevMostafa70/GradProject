<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Interview;
use App\Models\FinalReport;
use App\Services\LLMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserDashboardController extends Controller
{
    protected LLMService $llmService;

    public function __construct(LLMService $llmService)
    {
        $this->llmService = $llmService;
    }

    /**
     * Get all dashboard data in one request
     * GET /api/user/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $stats = $this->getStats($user);
        $progress = $this->getProgressData($user, $request->get('period', 'month'));
        $weaknesses = $this->getWeaknesses($user, $request->get('weakness_limit', 5));

        $dailyQuestions = null;
        if ($request->boolean('include_questions', false)) {
            $dailyQuestions = $this->getDailyQuestions($user, 3);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'progress' => $progress,
                'weaknesses' => $weaknesses,
                'daily_questions' => $dailyQuestions,
            ],
        ]);
    }

    /**
     * Get dashboard statistics only
     * GET /api/user/dashboard/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $stats = $this->getStats($user);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get progress chart data
     * GET /api/user/dashboard/progress
     */
    public function progress(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->get('period', 'month');
        $progress = $this->getProgressData($user, $period);

        return response()->json([
            'success' => true,
            'data' => $progress,
        ]);
    }

    /**
     * Get user weaknesses
     * GET /api/user/dashboard/weaknesses
     */
    public function weaknesses(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->get('limit', 5);
        $weaknesses = $this->getWeaknesses($user, $limit);

        return response()->json([
            'success' => true,
            'data' => $weaknesses,
        ]);
    }

    /**
     * Get daily practice questions
     * GET /api/user/dashboard/daily-questions
     */
    public function dailyQuestions(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $request->get('count', 3);
        $questions = $this->getDailyQuestions($user, $count);

        return response()->json([
            'success' => true,
            'data' => $questions,
        ]);
    }

    // ==================== Private Helper Methods ====================

    private function getStats(User $user): array
    {
        $completedInterviews = $user->completedInterviews()->with('finalReport')->get();
        $totalInterviews = $completedInterviews->count();

        $averageScore = null;
        if ($totalInterviews > 0) {
            $avgRaw = $completedInterviews->avg(function ($interview) {
                return $interview->finalReport?->overall_score ?? 0;
            });
            $averageScore = round($avgRaw * 10, 2);
        }

        $bestScore = null;
        if ($totalInterviews > 0) {
            $bestRaw = $completedInterviews->max(function ($interview) {
                return $interview->finalReport?->overall_score ?? 0;
            });
            $bestScore = round($bestRaw * 10, 2);
        }

        $totalPracticeMinutes = (int) $user->answers()
            ->whereHas('interview', function ($query) {
                $query->whereIn('status', ['completed', 'completed_with_report']);
            })
            ->sum('duration_seconds') / 60;

        $recentInterviews = $completedInterviews
            ->sortByDesc('created_at')
            ->take(3)
            ->map(function ($interview) {
                return [
                    'id' => $interview->id,
                    'position' => $interview->position,
                    'date' => $interview->completed_at?->format('Y-m-d') ?? $interview->created_at->format('Y-m-d'),
                    'score' => $interview->finalReport ? round($interview->finalReport->overall_score * 10, 2) : null,
                ];
            })
            ->values();

        return [
            'total_interviews' => $totalInterviews,
            'average_score' => $averageScore,
            'best_score' => $bestScore,
            'total_practice_minutes' => round($totalPracticeMinutes),
            'recent_interviews' => $recentInterviews,
        ];
    }

    private function getProgressData(User $user, string $period = 'month'): array
    {
        $query = $user->interviews()
            ->whereIn('status', ['completed', 'completed_with_report'])
            ->with('finalReport')
            ->orderBy('completed_at', 'asc');

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

    private function getWeaknesses(User $user, int $limit = 5): array
    {
        $evaluations = DB::table('evaluations')
            ->join('answers', 'evaluations.answer_id', '=', 'answers.id')
            ->join('interviews', 'answers.interview_id', '=', 'interviews.id')
            ->where('interviews.user_id', $user->id)
            ->whereIn('interviews.status', ['completed', 'completed_with_report'])
            ->whereNotNull('evaluations.weaknesses')
            ->select('evaluations.weaknesses', 'evaluations.score')
            ->get();

        if ($evaluations->isEmpty()) {
            return [];
        }

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

        usort($weaknesses, function ($a, $b) {
            return $b['occurrences'] - $a['occurrences'];
        });

        return array_slice($weaknesses, 0, $limit);
    }

    private function getDailyQuestions(User $user, int $count = 3): array
    {
        $weaknesses = $this->getWeaknesses($user, 3);

        $lastInterview = $user->interviews()
            ->whereIn('status', ['completed', 'completed_with_report'])
            ->orderBy('created_at', 'desc')
            ->first();

        $position = $lastInterview?->position ?? 'Software Developer';
        $skills = $lastInterview?->skills ?? ['general'];

        if (!is_array($skills)) {
            $skills = ['general'];
        }

        $skillsList = implode(', ', $skills);

        $weaknessText = '';
        if (!empty($weaknesses)) {
            $weaknessText = "The user struggles with:\n";
            foreach ($weaknesses as $w) {
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
