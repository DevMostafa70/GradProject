<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::routes([
    'middleware' => ['auth:sanctum'],
]);
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});


Broadcast::channel('interview.{interviewId}', function ($user, $interviewId) {
        Log::info("I am in the auth route");

    if (!$user) return false;

    $interview = \App\Models\Interview::find($interviewId);
    return $interview && (int)$interview->user_id === (int)$user->id;
});
