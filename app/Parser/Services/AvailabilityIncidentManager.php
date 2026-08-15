<?php

namespace App\Parser\Services;

use App\Models\Parser\AvailabilityCheck;
use App\Models\Parser\AvailabilityIncident;
use App\Parser\Notifications\AvailabilityIncidentNotifier;

class AvailabilityIncidentManager
{
    public function __construct(
        private readonly AvailabilityIncidentNotifier $notifier,
    ) {}

    public function process(AvailabilityCheck $check): void
    {
        $incident = AvailabilityIncident::query()
            ->where('court_id', $check->court_id)
            ->whereIn('status', ['suspected', 'open'])
            ->latest('id')
            ->first();

        if ($check->isSuccessful()) {
            $this->recordSuccess($check, $incident);

            return;
        }

        $this->recordFailure($check, $incident);
    }

    private function recordFailure(AvailabilityCheck $check, ?AvailabilityIncident $incident): void
    {
        $incident ??= AvailabilityIncident::query()->create([
            'court_id' => $check->court_id,
            'status' => 'suspected',
            'opened_at' => $check->checked_at,
            'last_checked_at' => $check->checked_at,
            'initial_outcome' => $check->outcome,
            'last_outcome' => $check->outcome,
        ]);

        $wasOpen = $incident->status === 'open';
        $incident->failed_checks++;
        $incident->consecutive_failures++;
        $incident->consecutive_successes = 0;
        $incident->last_outcome = $check->outcome;
        $incident->last_checked_at = $check->checked_at;
        $incident->worst_http_status = $this->worstHttpStatus($incident->worst_http_status, $check->http_status);
        $incident->summary = $check->error_message;

        if ($incident->consecutive_failures >= max(1, (int) config('monitoring.sudrf.failure_threshold', 2))) {
            $incident->status = 'open';
            $incident->confirmed_at ??= $check->checked_at;
        }

        $incident->save();
        $check->update(['availability_incident_id' => $incident->id]);

        if (! $wasOpen && $incident->status === 'open') {
            $this->notifier->opened($incident);
        }
    }

    private function recordSuccess(AvailabilityCheck $check, ?AvailabilityIncident $incident): void
    {
        if (! $incident instanceof AvailabilityIncident) {
            return;
        }

        $wasOpen = $incident->status === 'open';
        $incident->successful_checks++;
        $incident->consecutive_successes++;
        $incident->consecutive_failures = 0;
        $incident->last_outcome = $check->outcome;
        $incident->last_checked_at = $check->checked_at;

        if ($incident->consecutive_successes >= max(1, (int) config('monitoring.sudrf.recovery_threshold', 2))) {
            $incident->status = 'resolved';
            $incident->resolved_at = $check->checked_at;
        }

        $incident->save();
        $check->update(['availability_incident_id' => $incident->id]);

        if ($wasOpen && $incident->status === 'resolved') {
            $this->notifier->resolved($incident);
        }
    }

    private function worstHttpStatus(?int $current, ?int $candidate): ?int
    {
        if ($candidate === null) {
            return $current;
        }

        if ($current === null || $candidate >= 500 || $candidate === 429 || $candidate === 403) {
            return $candidate;
        }

        return $current;
    }
}
