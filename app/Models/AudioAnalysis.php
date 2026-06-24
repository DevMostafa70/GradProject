<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioAnalysis extends Model
{
    use HasFactory;

    protected $table = 'audio_analysis';

    protected $fillable = [
        'answer_id',
        'interview_id',
        'speaking_rate',
        'filler_word_count',
        'filler_words_found',
        'voice_stability',
        'pauses_percentage',
        'sentiment_scores',
        'confidence_level',
        'hesitation_score',
        'full_analysis_data',
    ];

    protected $casts = [
        'filler_words_found' => 'array',
        'sentiment_scores' => 'array',
        'full_analysis_data' => 'array',
        'speaking_rate' => 'decimal:2',
        'voice_stability' => 'decimal:2',
        'pauses_percentage' => 'decimal:2',
        'confidence_level' => 'decimal:2',
        'hesitation_score' => 'decimal:2',
    ];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }
}
