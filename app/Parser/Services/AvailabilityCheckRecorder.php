<?php

namespace App\Parser\Services;

use App\Models\Parser\AvailabilityCheck;
use App\Models\Parser\Court;
use App\Models\Parser\RequestLog;
use App\Parser\DTO\AvailabilityProbeResult;

class AvailabilityCheckRecorder
{
    public function __construct(
        private readonly AvailabilityOutcomeClassifier $classifier,
        private readonly AvailabilityIncidentManager $incidentManager,
    ) {}

    public function fromRequestLog(RequestLog $requestLog, string $source = 'parser'): AvailabilityCheck
    {
        $check = AvailabilityCheck::query()->firstOrCreate(
            ['request_log_id' => $requestLog->id],
            [
                'court_id' => $requestLog->court_id,
                'source' => $source,
                'endpoint_type' => $this->endpointType($requestLog->url),
                'url' => $requestLog->url,
                'checked_at' => $requestLog->created_at,
                'outcome' => $requestLog->status_code !== null
                    ? $this->classifier->fromHttpStatus($requestLog->status_code)
                    : $this->classifier->fromError($requestLog->error_type, $requestLog->error_message),
                'http_status' => $requestLog->status_code,
                'duration_ms' => $requestLog->duration_ms,
                'response_size_bytes' => $requestLog->response_size_bytes,
                'error_type' => $requestLog->error_type,
                'error_message' => $requestLog->error_message,
                'probe_node' => config('monitoring.sudrf.probe_node'),
            ],
        );

        if ($check->wasRecentlyCreated) {
            $this->incidentManager->process($check);
        }

        return $check->refresh();
    }

    private function endpointType(string $url): string
    {
        if (str_contains($url, 'H_date=')) {
            return 'case_list';
        }

        if (str_contains($url, 'name_op=case')) {
            return 'case_card';
        }

        return 'sudrf_page';
    }

    public function fromProbe(Court $court, AvailabilityProbeResult $result): AvailabilityCheck
    {
        $check = AvailabilityCheck::query()->create([
            'court_id' => $court->id,
            'source' => 'scheduled_probe',
            'endpoint_type' => 'case_list',
            'url' => $result->url,
            'checked_at' => now(),
            'outcome' => $result->outcome,
            'http_status' => $result->httpStatus,
            'duration_ms' => $result->durationMs,
            'response_size_bytes' => $result->responseSizeBytes,
            'retry_after_seconds' => $result->retryAfterSeconds,
            'error_type' => $result->errorType,
            'error_message' => $result->errorMessage,
            'response_hash' => $result->responseHash,
            'probe_node' => config('monitoring.sudrf.probe_node'),
        ]);

        $this->incidentManager->process($check);

        return $check->refresh();
    }
}
