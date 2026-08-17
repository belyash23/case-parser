<?php

namespace App\Admin\Actions;

use App\Enums\Parser\CrawlCampaignStatus;
use App\Models\Parser\CrawlCampaign;
use App\Parser\Services\CrawlCampaignManager;
use InvalidArgumentException;

class ControlCrawlCampaign
{
    public function __construct(private readonly CrawlCampaignManager $campaignManager) {}

    public function pause(CrawlCampaign $campaign): void
    {
        $this->setAutoResume($campaign, false);
        $this->campaignManager->pause($campaign->refresh());
    }

    public function resume(CrawlCampaign $campaign): void
    {
        if ($campaign->status->isTerminal()) {
            throw new InvalidArgumentException('Завершённую кампанию нельзя возобновить.');
        }

        $this->setAutoResume($campaign, true);
    }

    public function finish(CrawlCampaign $campaign): void
    {
        $this->campaignManager->finish($campaign, CrawlCampaignStatus::Completed);
    }

    public function cancel(CrawlCampaign $campaign): void
    {
        $this->campaignManager->finish($campaign, CrawlCampaignStatus::Cancelled);
    }

    private function setAutoResume(CrawlCampaign $campaign, bool $autoResume): void
    {
        $settings = $campaign->settings_json ?? [];
        $settings['auto_resume'] = $autoResume;
        $campaign->forceFill(['settings_json' => $settings])->save();
    }
}
