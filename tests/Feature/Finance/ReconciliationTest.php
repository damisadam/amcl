<?php

namespace Tests\Feature\Finance;

use App\Enums\ReconciliationStatus;
use App\Livewire\Ops\Finance\Reconciliation\Index;
use App\Livewire\Ops\Finance\Reconciliation\Show;
use App\Models\BankAccount;
use App\Models\ReconciliationBatch;
use App\Models\SettlementRecord;
use App\Models\SuspenseItem;
use App\Services\Accounting\FinancialReports;
use App\Services\Wallet\WalletService;
use Livewire\Livewire;

class ReconciliationTest extends FinanceTestCase
{
    public function test_run_detects_matched_mismatch_missing_and_moves_unidentified_receipt_to_suspense(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t);
        $wallet = $cu->customer->wallet()->firstOrFail();
        $ws = app(WalletService::class);
        $t1 = $ws->topUp($wallet, '1000', 'raast', 'k1', 'success');
        $t2 = $ws->topUp($wallet->fresh(), '2000', 'raast', 'k2', 'success');
        $t3 = $ws->topUp($wallet->fresh(), '3000', 'raast', 'k3', 'success');
        $safe = BankAccount::where('purpose', 'safeguarded')->firstOrFail();

        // Bank discrepancies (simulated statement): t2 settled for a different amount, t3 missing, plus an unidentified credit.
        SettlementRecord::where('provider_ref', $t2->fresh()->paymentOrder->provider_ref)->update(['amount' => '1990.00']);
        SettlementRecord::where('provider_ref', $t3->fresh()->paymentOrder->provider_ref)->delete();
        SettlementRecord::create(['bank_account_id' => $safe->id, 'provider' => 'raast', 'value_date' => today(), 'provider_ref' => 'UNKNOWN-REF-1',
            'narration' => 'RAAST credit – no reference (SIMULATED)', 'direction' => 'credit', 'amount' => '750.00']);

        $officer = $this->accountant($t);
        Livewire::actingAs($officer)->test(Index::class)->set('bank_account_id', (string) $safe->id)->call('run')->assertHasNoErrors();
        $batch = ReconciliationBatch::firstOrFail();
        $results = $batch->items()->pluck('result')->countBy()->all();
        $this->assertSame(1, $results['matched'] ?? 0);
        $this->assertSame(1, $results['amount_mismatch'] ?? 0);
        $this->assertSame(1, $results['missing_external'] ?? 0);
        $this->assertSame(1, $results['missing_internal'] ?? 0);

        $unknown = $batch->items()->where('result', 'missing_internal')->firstOrFail();
        Livewire::actingAs($officer)->test(Show::class, ['batch' => $batch])->call('toSuspense', $unknown->id)->assertHasNoErrors();
        $this->assertSame(1, SuspenseItem::where('status', 'open')->count());
        $this->assertSame('750.00', app(FinancialReports::class)->roleBalance('suspense'));

        Livewire::actingAs($officer)->test(Show::class, ['batch' => $batch])->call('submit')->assertHasErrors('action'); // unresolved exceptions remain
        foreach ($batch->items()->whereIn('result', ['amount_mismatch', 'missing_external'])->get() as $i) {
            Livewire::actingAs($officer)->test(Show::class, ['batch' => $batch])->call('askAnnotate', $i->id)->set('resolution', 'bank_query_raised')->set('notes', 'Raised with sponsor bank')->call('annotate')->assertHasNoErrors();
        }
        Livewire::actingAs($officer)->test(Show::class, ['batch' => $batch->fresh()])->call('submit')->assertHasNoErrors();
        $this->assertSame(ReconciliationStatus::PendingApproval, $batch->fresh()->status);

        Livewire::actingAs($this->financeOfficer($t))->test(Show::class, ['batch' => $batch->fresh()])->call('decide', 'approve')->assertHasNoErrors();
        $this->assertSame(ReconciliationStatus::Approved, $batch->fresh()->status);
        $this->assertTrue(app(FinancialReports::class)->trialBalance(null, today()->toDateString())['balanced']);
    }
}
