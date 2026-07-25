<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CandidateIdentityEvidence extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * تحديد اسم الجدول يدويًا لأن Laravel يعتبر
     * كلمة evidence غير معدودة، بينما اسم الجدول
     * في قاعدة البيانات هو candidate_identity_evidences.
     */
    protected $table = 'candidate_identity_evidences';

    public const TYPE_DOCUMENT_FRONT = 'document_front';
    public const TYPE_DOCUMENT_BACK = 'document_back';
    public const TYPE_SELFIE = 'selfie';
    public const TYPE_LIVENESS = 'liveness';
    public const TYPE_INTERVIEW_SNAPSHOT = 'interview_snapshot';

    protected $fillable = [
        'verification_id',
        'interview_id',
        'question_id',
        'type',
        'disk',
        'path',
        'mime_type',
        'file_size',
        'sha256',
        'captured_at',
        'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'captured_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * عملية التحقق المرتبطة بالدليل.
     */
    public function verification(): BelongsTo
    {
        return $this->belongsTo(
            CandidateIdentityVerification::class,
            'verification_id'
        );
    }

    /**
     * المقابلة المرتبطة بالدليل.
     */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(
            Interview::class,
            'interview_id'
        );
    }

    /**
     * السؤال المرتبط بالصورة العشوائية، إن وجد.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(
            Question::class,
            'question_id'
        );
    }
}