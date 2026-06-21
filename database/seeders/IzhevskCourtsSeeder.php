<?php

namespace Database\Seeders;

use App\Models\Parser\Court;
use Illuminate\Database\Seeder;

class IzhevskCourtsSeeder extends Seeder
{
    public function run(): void
    {
        $courts = [
            [
                'name' => 'Industrialnyy District Court of Izhevsk',
                'base_url' => 'https://industrialnyy.udm.sudrf.ru',
            ],
            [
                'name' => 'Leninskiy District Court of Izhevsk',
                'base_url' => 'https://leninskiy.udm.sudrf.ru',
            ],
            [
                'name' => 'Oktyabrskiy District Court of Izhevsk',
                'base_url' => 'https://oktyabrskiy.udm.sudrf.ru',
            ],
            [
                'name' => 'Pervomayskiy District Court of Izhevsk',
                'base_url' => 'https://pervomayskiy.udm.sudrf.ru',
            ],
            [
                'name' => 'Ustinovskiy District Court of Izhevsk',
                'base_url' => 'https://ustinovskiy.udm.sudrf.ru',
            ],
        ];

        foreach ($courts as $courtData) {
            Court::query()->updateOrCreate(
                ['base_url' => $courtData['base_url']],
                [
                    ...$courtData,
                    'region' => 'Udmurt Republic',
                    'city' => 'Izhevsk',
                    'court_level' => 'district',
                    'court_type' => 'municipal',
                    'source_type' => 'sudrf',
                    'layout_type' => 'sudrf_mobile',
                    'status' => 'active',
                    'is_enabled' => true,
                    'min_request_interval_ms' => 3000,
                    'max_parallel_requests' => 1,
                    'timeout_ms' => 30000,
                    'retry_count' => 2,
                    'backoff_multiplier' => 1.8,
                    'crawl_priority' => 100,
                ],
            );
        }
    }
}
