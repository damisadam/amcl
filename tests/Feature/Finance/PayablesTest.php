<?php

namespace Tests\Feature\Finance;

use App\Enums\VendorPaymentStatus;
use App\Livewire\Ops\Finance\Payables;
use App\Models\JournalVoucher;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Services\Accounting\FinancialReports;
use Livewire\Livewire;

class PayablesTest extends FinanceTestCase
{
    private function billAndRequest($t, $maker, $vendor, string $amount): VendorPayment
    {
        Livewire::actingAs($maker)->test(Payables::class)
            ->set('bill.vendor_id', (string) $vendor->id)->set('bill.amount', $amount)->set('bill.description', 'Office stationery')->set('bill.wht_rate', '4.5')
            ->call('createBill')->assertHasNoErrors();
        $bill = VendorBill::latest('id')->firstOrFail();
        Livewire::actingAs($maker)->test(Payables::class)
            ->set('pay.vendor_id', (string) $vendor->id)->set('pay.vendor_bill_id', (string) $bill->id)->call('requestPayment')->assertHasNoErrors();

        return VendorPayment::latest('id')->firstOrFail();
    }

    public function test_bill_request_approve_release_success_posts_gl_and_marks_paid(): void
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $maker = $this->accountant($t);
        $checker = $this->financeOfficer($t);

        $p = $this->billAndRequest($t, $maker, $vendor, '100000');
        $this->assertSame(VendorPaymentStatus::PendingApproval, $p->status);
        $this->assertSame('95500.00', (string) $p->net_amount); // net of 4.5% WHT
        $this->assertSame('95500.00', app(FinancialReports::class)->roleBalance('vendor_payable'));

        Livewire::actingAs($checker)->test(Payables::class)->set('tab', 'requests')->call('decide', $p->id, 'approve')->assertHasNoErrors();
        $this->assertSame(VendorPaymentStatus::Approved, $p->fresh()->status);

        Livewire::actingAs($checker)->test(Payables::class)->set('tab', 'release')->set('scenario', 'success')->call('release', $p->id)->assertHasNoErrors();
        $p->refresh();
        $this->assertSame(VendorPaymentStatus::Paid, $p->status);
        $this->assertNotNull($p->journal_voucher_id);
        $this->assertSame('paid', $p->bill->status);
        $this->assertSame('0.00', app(FinancialReports::class)->roleBalance('vendor_payable'));
        $this->assertSame('4500.00', app(FinancialReports::class)->roleBalance('wht_payable'));
        $this->assertTrue(app(FinancialReports::class)->trialBalance(null, today()->toDateString())['balanced']);
    }

    public function test_failed_release_does_not_mark_vendor_paid_or_post(): void
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $p = $this->billAndRequest($t, $this->accountant($t), $vendor, '20000');
        $checker = $this->financeOfficer($t);
        Livewire::actingAs($checker)->test(Payables::class)->call('decide', $p->id, 'approve');

        Livewire::actingAs($checker)->test(Payables::class)->set('scenario', 'failure')->call('release', $p->id);
        $p->refresh();
        $this->assertSame(VendorPaymentStatus::Failed, $p->status);
        $this->assertNull($p->journal_voucher_id);
        $this->assertSame('open', $p->bill->fresh()->status);
        $this->assertSame(0, JournalVoucher::where('event_type', 'vendor_payment')->count());
        $this->assertSame('19100.00', app(FinancialReports::class)->roleBalance('vendor_payable'));
    }

    public function test_requester_cannot_approve_or_release_own_payment(): void
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $both = $this->staff($t, ['accountant', 'finance_officer']);
        $p = $this->billAndRequest($t, $both, $vendor, '5000');

        Livewire::actingAs($both)->test(Payables::class)->call('decide', $p->id, 'approve')->assertHasErrors('action');
        $this->assertSame(VendorPaymentStatus::PendingApproval, $p->fresh()->status);

        Livewire::actingAs($this->financeOfficer($t))->test(Payables::class)->call('decide', $p->id, 'approve');
        Livewire::actingAs($both)->test(Payables::class)->call('release', $p->id)->assertHasErrors('action');
        $this->assertSame(VendorPaymentStatus::Approved, $p->fresh()->status);
    }

    public function test_loan_officer_cannot_open_payables(): void
    {
        $t = $this->makeTenant('alpha');
        Livewire::actingAs($this->staff($t, 'loan_officer'))->test(Payables::class)->assertForbidden();
    }
}
