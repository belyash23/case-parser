<?php

namespace App\Console\Commands;

use App\Models\Parser\CaseChainLink;
use App\Models\Parser\CaseInstance;
use App\Parser\Normalizers\ResultNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillJoinedCasesCommand extends Command
{
    protected $signature = 'parser:backfill-joined-cases {--dry-run : Count matching rows without changing them}';

    protected $description = 'Reclassify stored cases that were joined to another case.';

    public function handle(ResultNormalizer $normalizer): int
    {
        $matched = 0;
        $updated = 0;

        CaseInstance::query()
            ->whereNotNull('result_raw')
            ->orderBy('id')
            ->chunkById(500, function ($instances) use ($normalizer, &$matched, &$updated): void {
                foreach ($instances as $instance) {
                    if ($normalizer->normalize($instance->result_raw) !== 'joined_to_another_case') {
                        continue;
                    }

                    $matched++;

                    if ($this->option('dry-run')) {
                        continue;
                    }

                    DB::transaction(function () use ($instance, &$updated): void {
                        $instance->forceFill([
                            'court_instance_status_normalized' => 'closed',
                            'dispute_status_normalized' => 'merged',
                            'disposition_type' => 'joined_to_another_case',
                            'result_normalized' => 'joined_to_another_case',
                        ])->save();

                        $courtCase = $instance->courtCase;

                        if ($courtCase !== null) {
                            $courtCase->forceFill([
                                'dispute_status' => 'merged',
                                'final_disposition_type' => 'joined_to_another_case',
                                'chain_status' => 'merge_pending',
                                'is_training_candidate' => false,
                            ])->save();
                        }

                        CaseChainLink::query()->firstOrCreate(
                            [
                                'source_instance_id' => $instance->id,
                                'target_instance_id' => null,
                                'link_type' => 'joined_to_another_case',
                            ],
                            [
                                'status' => 'pending',
                                'confidence' => 1,
                                'evidence_json' => [
                                    'source_url' => $instance->source_url,
                                    'case_uid' => $instance->case_uid,
                                    'external_case_id' => $instance->external_case_id,
                                    'case_number' => $instance->external_case_number,
                                    'result_raw' => $instance->result_raw,
                                    'result_normalized' => 'joined_to_another_case',
                                ],
                            ],
                        );

                        $updated++;
                    });
                }
            });

        $this->info($this->option('dry-run')
            ? "Matched {$matched} joined cases; no changes made."
            : "Reclassified {$updated} of {$matched} matched joined cases.");

        return self::SUCCESS;
    }
}
