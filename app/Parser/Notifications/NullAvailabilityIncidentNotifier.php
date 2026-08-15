<?php

namespace App\Parser\Notifications;

use App\Models\Parser\AvailabilityIncident;

class NullAvailabilityIncidentNotifier implements AvailabilityIncidentNotifier
{
    public function opened(AvailabilityIncident $incident): void
    {
    }

    public function resolved(AvailabilityIncident $incident): void
    {
    }
}
