<?php

namespace Tests\Feature\Customer;

use App\Enums\ComplaintCategory;
use App\Services\Support\ComplaintService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Every customer screen renders for a customer with a live loan, wallet activity and a complaint (EN + UR). */
class ScreensRenderTest extends TestCase
{
    use BuildsTenants, ProcessesApplications, RefreshDatabase;

    public function test_all_customer_screens_render(): void
    {
        $tenant = $this->makeTenant('alpha');
        [$user, $loan] = $this->disbursedLoan($tenant);
        TenantContext::set($tenant);
        $payment = app(\App\Services\Lending\RepaymentService::class)->recordManual($loan, '2000', 'cash', 'C-1', $this->staff($tenant, 'accountant'));
        $wallet = $user->customer->wallet()->first();
        app(\App\Services\Wallet\WalletService::class)->topUp($wallet, '5000', 'raast', 'k1', 'success');
        $ticket = app(ComplaintService::class)->open($user->customer, ComplaintCategory::General, 'Need statement', 'Please send my statement', null, 'web', $user);
        $app = $loan->application;
        $product = $loan->product;
        $txn = $wallet->transactions()->first();

        $urls = ['/app', '/app/onboarding', '/app/products', "/app/products/{$product->id}", "/app/apply/{$product->id}", '/app/applications',
            "/app/applications/{$app->id}", '/app/financing', "/app/financing/{$loan->id}", "/app/receipts/{$payment->id}", '/app/pay',
            '/app/wallet', '/app/wallet/top-up', '/app/wallet/transfer', '/app/wallet/withdraw', '/app/wallet/bills', '/app/wallet/merchant',
            '/app/wallet/settings', '/app/transactions', "/app/transactions/wallet/{$txn->id}/receipt", '/app/documents', '/app/notifications',
            '/app/support', "/app/support/{$ticket->id}", '/app/profile'];

        foreach (['en', 'ur'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            foreach ($urls as $url) {
                $res = $this->actingAs($user->fresh())->get($url);
                $this->assertContains($res->status(), [200], "$url ($locale) returned {$res->status()} ".($res->exception ? $res->exception->getMessage() : ""));
            }
        }
    }
}
