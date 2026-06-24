<?php

namespace App\Policies;

use App\Models\Interview;
use App\Models\User;

class InterviewPolicy
{
    /**
     * Determine whether the user can view the interview.
     */
    public function view(User $user, Interview $interview): bool
    {
        return (int) $user->id === (int) $interview->user_id;
    }

    /**
     * Determine whether the user can update the interview.
     */
    public function update(User $user, Interview $interview): bool
    {
        return (int) $user->id === (int) $interview->user_id;
    }
}
