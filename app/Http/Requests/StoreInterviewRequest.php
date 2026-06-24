<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position' => ['required', 'string', 'max:255'],
            'experience_level' => [
                'required',
                Rule::in(['junior', 'mid', 'senior', 'lead', 'executive'])
            ],
            'difficulty' => [
                'required',
                Rule::in(['easy', 'medium', 'hard'])
            ],
            'skills' => ['required', 'array', 'min:1'],
            'skills.*' => ['string', 'max:100'],
            'number_of_questions' => ['integer', 'min:3', 'max:10', 'default:5'],
        ];
    }

    public function messages(): array
    {
        return [
            'skills.required' => 'Please specify at least one skill for the interview.',
            'skills.min' => 'Please specify at least one skill for the interview.',
            'number_of_questions.min' => 'Minimum 3 questions required.',
            'number_of_questions.max' => 'Maximum 10 questions allowed.',
        ];
    }
}
