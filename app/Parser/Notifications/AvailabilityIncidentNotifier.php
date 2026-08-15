<?php

namespace App\Parser\Notifications;

use App\Models\Parser\AvailabilityIncident;

interface AvailabilityIncidentNotifier
{
    public function opened(AvailabilityIncident $incident): void;

    public function resolved(AvailabilityIncident $incident): void;
}
