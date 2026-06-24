<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Illuminate\Support\Collection;

class QuestionsImport implements ToCollection, WithHeadingRow, WithValidation
{
    protected array $questions = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $question = [
                'question' => trim($row['question']),
                'type' => $this->getValidatedType($row['type'] ?? null),
                'difficulty' => $this->getValidatedDifficulty($row['difficulty'] ?? null),
            ];

            if (!empty($question['question'])) {
                $this->questions[] = $question;
            }
        }
    }

    public function rules(): array
    {
        return [
            'question' => 'required|string|max:1000',
            'type' => 'nullable|in:technical,behavioral,situational',
            'difficulty' => 'nullable|in:easy,medium,hard',
        ];
    }

    public function customValidationMessages()
    {
        return [
            'question.required' => 'The question field is required in each row.',
            'type.in' => 'Type must be technical, behavioral, or situational.',
            'difficulty.in' => 'Difficulty must be easy, medium, or hard.',
        ];
    }

    public function getQuestions(): array
    {
        return $this->questions;
    }

    public function getTotalCount(): int
    {
        return count($this->questions);
    }

    private function getValidatedType(?string $type): string
    {
        $validTypes = ['technical', 'behavioral', 'situational'];
        $type = strtolower(trim($type ?? 'behavioral'));

        return in_array($type, $validTypes) ? $type : 'behavioral';
    }

    private function getValidatedDifficulty(?string $difficulty): string
    {
        $validDifficulties = ['easy', 'medium', 'hard'];
        $difficulty = strtolower(trim($difficulty ?? 'medium'));

        return in_array($difficulty, $validDifficulties) ? $difficulty : 'medium';
    }
}
