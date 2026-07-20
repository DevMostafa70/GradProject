<?php

namespace App\Events;

use App\Models\FinalReport;
use App\Models\Interview;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FinalReportReady implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Interview $interview,
        public FinalReport $finalReport
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('interview.' . $this->interview->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'final.report.ready';
    }

    public function broadcastWith(): array
    {
        return [
            'interview_id' => $this->interview->id,
            'status' => 'ready',
            'redirect_url' => "/interviews/{$this->interview->id}/report",
            'preview' => [
                'overall_score' => $this->finalReport->overall_score,
                'adjusted_score' => $this->finalReport->adjusted_score,
                'hiring_recommendation' => $this->finalReport->hiring_recommendation,
                'total_violations' => $this->finalReport->total_violations,
                'cheating_severity_score' => $this->finalReport->cheating_severity_score,
                'generated_at' => $this->finalReport->generated_at?->toISOString(),
            ],
        ];
    }
}