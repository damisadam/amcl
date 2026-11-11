<?php

namespace Tests\Feature\Ops\Admin;

use App\Enums\ComplaintCategory;
use App\Models\PaymentOrder;
use App\Services\Support\ComplaintService;
use App\Services\Wallet\WalletService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Every F4 screen and tab renders over HTTP (full middleware stack) for a tenant admin with seeded records. */
class ScreensRenderTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_all_screens_and_tabs_render(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t);
        TenantContext::set($t);
        $w = $cu->customer->wallet()->first();
        $txn = app(WalletService::class)->topUp($w, '1500', 'raast', 'r-1', 'duplicate_callback', $cu);
        $order = PaymentOrder::where('payable_id', $txn->id)->firstOrFail();
        $complaint = app(ComplaintService::class)->open($cu->customer, ComplaintCategory::TransactionDispute, 'Dispute', 'Please check', $txn->fresh());
        $admin = $this->admin($t);
        $this->actingAs($admin);

        $urls = [
            route('ops.wallets.index'), route('ops.wallets.show', $w->id), route('ops.wallets.show', $w->id).'?tab=ledger',
            route('ops.wallet-transactions.index'), route('ops.payments.index'), route('ops.payments.index', ['tab' => 'callbacks']),
            route('ops.payments.show', $order->id), route('ops.complaints.index'), route('ops.complaints.show', $complaint->id),
            route('ops.admin.users'), route('ops.admin.roles'), route('ops.admin.workflows'), route('ops.admin.workflows', ['type' => 'vendor_payment']),
            route('ops.admin.integrations'), route('ops.admin.integrations', ['provider' => 'raast']), route('ops.admin.audit'),
        ];
        foreach (['hierarchy', 'departments', 'employees', 'products', 'funds'] as $tab) {
            $urls[] = route('ops.admin.org', ['tab' => $tab]);
        }
        foreach (['settings', 'holidays', 'payments', 'categories', 'documents', 'branding', 'blacklist'] as $tab) {
            $urls[] = route('ops.admin.config', ['tab' => $tab]);
        }
        foreach (['content', 'templates', 'log'] as $tab) {
            $urls[] = route('ops.admin.content', ['tab' => $tab]);
        }
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('ops.payments.show', $order->id))->assertSee('duplicate');
        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Ops\Wallets\Show::class, ['wallet' => $w])->set('tab', 'ledger')->assertSee('Immutable ledger entries')->assertSee('Credit');
        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Ops\WalletTransactions\Index::class)->call('view', $txn->id)->assertSee($order->reference);
    }

    public function test_staff_without_admin_permissions_is_forbidden(): void
    {
        $t = $this->makeTenant('alpha');
        $this->actingAs($this->staff($t, 'visit_officer'));
        foreach (['ops.admin.users', 'ops.admin.roles', 'ops.admin.org', 'ops.admin.config', 'ops.admin.workflows', 'ops.admin.integrations', 'ops.admin.content', 'ops.admin.audit',
            'ops.wallets.index', 'ops.wallet-transactions.index', 'ops.payments.index', 'ops.complaints.index'] as $r) {
            $this->get(route($r))->assertForbidden();
        }
    }
}
