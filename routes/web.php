<?php

use Illuminate\Support\Facades\Route;
use OpenAI\Laravel\Facades\OpenAI;

Route::get('/', function () {
    return view('welcome');
});


Route::get("test" , function () {

var_dump(env('OPENAI_API_KEY'));});

Route::get('/test-openai', function () {
    try {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello I am Yahia Say hello from Laravel backend I am testing you']
            ],
        ]);

        return response()->json([
            'success' => true,
            'reply' => $response->choices[0]->message->content,
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});
