<?php

namespace Tests\Feature\Admin;

use App\Enums\Admin\ReportStatus;
use App\Enums\Parser\CrawlCampaignMode;
use App\Enums\Parser\CrawlCampaignStatus;
use App\Jobs\Admin\GenerateAdminReportJob;
use App\Jobs\Admin\RunParserJob;
use App\Models\Admin\AdminReport;
use App\Models\Parser\CrawlCampaign;
use App\Models\Parser\ParserSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_update_parser_settings_at_the_hard_interval_floor(): void
    {
        $admin = User::factory()->administrator()->create();
        $settings = ParserSetting::current()->attributesToArray();
        $settings['request_interval_ms'] = 1000;
        unset($settings['id'], $settings['created_at'], $settings['updated_at']);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $settings)
            ->assertSessionHasNoErrors();

        $this->assertSame(1000, ParserSetting::current()->request_interval_ms);
    }

    public function test_request_interval_below_one_second_is_rejected(): void
    {
        $admin = User::factory()->administrator()->create();
        $settings = ParserSetting::current()->attributesToArray();
        $settings['request_interval_ms'] = 999;
        unset($settings['id'], $settings['created_at'], $settings['updated_at']);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $settings)
            ->assertSessionHasErrors('request_interval_ms');
    }

    public function test_administrator_can_queue_an_initial_campaign(): void
    {
        Queue::fake();
        $admin = User::factory()->administrator()->create();

        $this->actingAs($admin)->post(route('admin.parser.initial.store'), [
            'from' => '2023-01-01',
            'to' => '2026-06-21',
            'court_ids' => [],
            'region_ids' => [],
            'skip_directory_sync' => false,
        ])->assertSessionHasNoErrors();

        Queue::assertPushed(RunParserJob::class, fn (RunParserJob $job): bool => $job->mode === 'initial'
            && $job->arguments['--from'] === '2023-01-01'
            && $job->arguments['--to'] === '2026-06-21');
    }

    public function test_pause_is_cooperative_and_disables_automatic_resume(): void
    {
        $admin = User::factory()->administrator()->create();
        $campaign = CrawlCampaign::query()->create([
            'mode' => CrawlCampaignMode::Initial,
            'status' => CrawlCampaignStatus::Running,
            'settings_json' => ['auto_resume' => true],
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.parser.campaigns.pause', $campaign))
            ->assertSessionHasNoErrors();

        $campaign->refresh();
        $this->assertSame(CrawlCampaignStatus::Paused, $campaign->status);
        $this->assertFalse($campaign->settings_json['auto_resume']);
    }

    public function test_report_generation_is_queued_and_expires_after_seven_days(): void
    {
        Queue::fake();
        $admin = User::factory()->administrator()->create();

        $this->actingAs($admin)->post(route('admin.reports.store'), [
            'type' => 'dataset',
            'format' => 'csv',
            'from' => '2025-01-01',
            'to' => '2025-12-31',
            'include_source_url' => false,
        ])->assertSessionHasNoErrors();

        $report = AdminReport::query()->firstOrFail();
        $this->assertSame(ReportStatus::Queued, $report->status);
        $this->assertTrue($report->expires_at->isSameDay(now()->addDays(7)));
        Queue::assertPushed(GenerateAdminReportJob::class, fn (GenerateAdminReportJob $job): bool => $job->reportId === $report->id);
    }
}
