<?php

namespace App\Events;

use App\Models\Interview;
use App\Models\FinalReport;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalReportReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Interview $interview;
    public FinalReport $finalReport;

    /**
     * Create a new event instance.
     */
    public function __construct(Interview $interview, FinalReport $finalReport)
    {
        Log::info("Event is fired...");
        $this->interview = $interview;
        $this->finalReport = $finalReport;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        logger('Broadcasting on interview');
        return [
            new PrivateChannel('interview.' . $this->interview->id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'final.report.ready';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        logger('Broadcast Payload Sent');
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
                'generated_at' => $this->finalReport->generated_at->toISOString(),
            ],
        ];
    }
}
