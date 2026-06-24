<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;

class InterviewAnalysisController extends Controller
{
    public function analyze(Request $request)
    {
        $request->validate([
            'question' => 'required|string',
            'transcript' => 'required|string',
        ]);

        $question = $request->question;
        $transcript = $request->transcript;

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '
You are a professional interview coach.

Analyze interview answers professionally.

Return ONLY valid JSON.

Do not return markdown.
Do not return explanations outside JSON.
',
                ],
                [
                    'role' => 'user',
                    'content' => "
Question:
{$question}

Candidate Answer:
{$transcript}

Return ONLY this JSON format:

{
  \"confidence\": 0-100,
  \"feedback\": \"short feedback\",
  \"strengths\": \"candidate strengths\",
  \"improvement\": \"improvement suggestion\"
}
",
                ],
            ],
        ]);

        $content = $response->choices[0]->message->content ?? '{}';

        $cleanJson = str_replace(
            ['```json', '```'],
            '',
            trim($content)
        );

        $decoded = json_decode($cleanJson, true);

        if (!$decoded) {
            return response()->json([
                'confidence' => 0,
                'feedback' => 'AI returned invalid JSON',
                'strengths' => '',
                'improvement' => '',
            ], 500);
        }

        return response()->json($decoded);
    }
}
