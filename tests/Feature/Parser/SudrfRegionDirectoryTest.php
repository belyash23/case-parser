<?php

namespace Tests\Feature\Parser;

use App\Models\Parser\Court;
use App\Models\Parser\Region;
use App\Models\Parser\SourceRuntimeState;
use App\Parser\Contracts\RequestSleeper;
use App\Parser\Services\SudrfDirectorySyncService;
use App\Parser\Services\SudrfRegionDirectoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\AdvancingRequestSleeper;
use Tests\TestCase;

class SudrfRegionDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->app->instance(RequestSleeper::class, new AdvancingRequestSleeper);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_synchronizes_unique_regions_from_the_official_directory_select(): void
    {
        Http::fake([
            'https://sudrf.ru/index.php*' => Http::response(<<<'HTML'
<!doctype html><html><body>
<select name="court_subj">
    <option value="0"></option>
    <option value="18">Удмуртская Республика</option>
    <option value="77">Город Москва</option>
</select>
<select name="court_subj">
    <option value="18">Удмуртская Республика</option>
</select>
</body></html>
HTML),
        ]);

        $regions = app(SudrfRegionDirectoryService::class)->sync();

        $this->assertCount(2, $regions);
        $this->assertSame(2, Region::query()->count());
        $this->assertSame('Удмуртская Республика', Region::query()->where('sudrf_region_id', 18)->value('name'));
        $this->assertTrue(Region::query()->where('sudrf_region_id', 18)->firstOrFail()->is_enabled);
        $this->assertNotNull(SourceRuntimeState::query()->where('source_type', 'sudrf')->first());
        Http::assertSentCount(1);
    }

    public function test_invalid_region_filter_is_rejected_without_an_http_request(): void
    {
        Http::fake();

        $this->artisan('parser:sync-directory', ['--region' => ['invalid']])
            ->expectsOutput('Each --region value must be a positive integer SUDRF region ID.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_full_directory_sync_can_be_limited_to_selected_regions(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'court_subj=18')) {
                return Http::response(<<<'HTML'
<!doctype html><html><body>
<a href="https://leninskiy--udm.sudrf.ru">Ленинский районный суд</a>
<a href="https://oktyabrskiy--udm.sudrf.ru">Октябрьский районный суд</a>
</body></html>
HTML);
            }

            return Http::response(<<<'HTML'
<!doctype html><html><body><select name="court_subj">
<option value="18">Удмуртская Республика</option>
<option value="77">Город Москва</option>
</select></body></html>
HTML);
        });

        $result = app(SudrfDirectorySyncService::class)->sync([18]);

        $this->assertSame(['regions' => 1, 'courts' => 2, 'failures' => 0], $result);
        $this->assertSame(2, Court::query()->count());
        $this->assertSame(
            Region::query()->where('sudrf_region_id', 18)->value('id'),
            Court::query()->where('name', 'Ленинский районный суд')->value('region_id'),
        );
        Http::assertSentCount(2);
    }
}
