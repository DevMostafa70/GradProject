<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resume extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'file_path',
        'file_name',
        'file_type',
        'extracted_text',
        'analysis_result',
        'improved_content',
        'target_position',
        'target_skills',
        'ats_score',
        'status',
        'analyzed_at',
    ];

    protected $casts = [
        'analysis_result' => 'array',
        'improved_content' => 'array',
        'target_skills' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
