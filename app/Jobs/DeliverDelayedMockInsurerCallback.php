<?php

namespace App\Jobs;

use App\Models\MockInsurerRecord;
use App\Services\ProviderCallbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliverDelayedMockInsurerCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $mockInsurerRecordId)
    {
    }

    public function handle(ProviderCallbackService $callbacks): void
    {
        $record = DB::transaction(function (): ?MockInsurerRecord {
            $record = MockInsurerRecord::query()
                ->lockForUpdate()
                ->find($this->mockInsurerRecordId);

            if ($record === null || $record->scenario !== 'DELAYED_CALLBACK') {
                return null;
            }

            if ($record->policy_number === null) {
                $record->policy_number = 'POL-'.Str::upper(Str::random(12));
                $record->status = 'ISSUED';
                $record->last_response = [
                    'outcome' => 'ISSUED',
                    'policy_number' => $record->policy_number,
                    'provider_reference' => $record->provider_reference,
                ];
                $record->save();
            }

            return $record;
        });

        if ($record === null) {
            return;
        }

        $callbacks->process([
            'provider_event_id' => 'EVENT-'.$record->provider_reference,
            'provider_reference' => $record->provider_reference,
            'event_type' => 'POLICY_ISSUED',
            'policy_number' => $record->policy_number,
        ]);
    }
}
