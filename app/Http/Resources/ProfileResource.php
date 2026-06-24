<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar ? asset('storage/' . $this->avatar) : null,
            'bio' => $this->bio,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'email_verified_at' => $this->email_verified_at,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'stats' => [
                'total_interviews' => $this->completedInterviews()->count(),
                'average_score' => $this->whenLoaded('finalReports', function () {
                    return $this->calculateAverageScore();
                }),
                'practice_days' => $this->calculatePracticeDays(),
            ],
        ];
    }
}
