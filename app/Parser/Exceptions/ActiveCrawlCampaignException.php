<?php

namespace App\Parser\Exceptions;

use RuntimeException;

class ActiveCrawlCampaignException extends RuntimeException
{
    public function __construct(public readonly int $campaignId, public readonly string $sourceType)
    {
        parent::__construct(sprintf('Crawl campaign %d is already active for %s.', $campaignId, $sourceType));
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [
            'campaign_id' => $this->campaignId,
            'source_type' => $this->sourceType,
        ];
    }
}
