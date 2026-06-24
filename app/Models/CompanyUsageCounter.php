<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyUsageCounter extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'period_start',
        'period_end',
        'jobs_created',
        'active_jobs_count',
        'candidates_imported',
        'interviews_started',
        'interviews_completed',
        'final_reports_generated',
        'cv_reviews_used',
        'emails_sent',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'jobs_created' => 'integer',
            'active_jobs_count' => 'integer',
            'candidates_imported' => 'integer',
            'interviews_started' => 'integer',
            'interviews_completed' => 'integer',
            'final_reports_generated' => 'integer',
            'cv_reviews_used' => 'integer',
            'emails_sent' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
