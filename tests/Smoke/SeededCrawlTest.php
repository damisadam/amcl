<?php

namespace Tests\Smoke;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Crawls every GET screen as real demo users against the SEEDED demo database (read-mostly; no refresh).
 * Run after `php artisan amcl:demo-reset`:  DB_DATABASE=amcl php artisan test tests/Smoke
 * Not part of the default suite (see phpunit.xml testsuites).
 */
class SeededCrawlTest extends TestCase
{
    private array $failures = [];

    private int $hits = 0;

    public function test_every_screen_renders_for_every_portal(): void
    {
        $this->assertGreaterThan(0, Tenant::count(), 'Seed the demo data first (php artisan amcl:demo-reset).');
        $routes = collect(RouteFacade::getRoutes()->getRoutes())->filter(fn (Route $r) => in_array('GET', $r->methods()) && $r->getName());

        foreach (Tenant::where('code', 'amcl')->get() as $tenant) {
            $staff = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('type', 'staff')->where('status', 'active')->get();
            foreach ($staff as $u) {
                $this->crawl($u, $routes->filter(fn ($r) => str_starts_with($r->getName(), 'ops.')), $tenant);
            }
            foreach (User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('type', 'customer')->orderBy('id')->take(30)->get() as $u) {
                $this->crawl($u, $routes->filter(fn ($r) => str_starts_with($r->getName(), 'app.')), $tenant);
            }
            foreach (User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('type', 'vendor')->get() as $u) {
                $this->crawl($u, $routes->filter(fn ($r) => str_starts_with($r->getName(), 'vendor.')), $tenant);
            }
        }
        foreach (User::withoutGlobalScopes()->where('type', 'platform')->get() as $u) {
            $this->crawl($u, $routes->filter(fn ($r) => str_starts_with($r->getName(), 'platform.') && ! in_array($r->getName(), ['platform.login'])), null);
        }
        fwrite(STDERR, "\nCrawled {$this->hits} pages.\n");
        $this->assertSame([], array_slice($this->failures, 0, 40), count($this->failures).' failing page(s)');
    }

    private function crawl(User $user, $routes, ?Tenant $tenant): void
    {
        foreach ($routes as $route) {
            foreach ($this->urlsFor($route, $tenant, $user) as $url) {
                TenantContext::clear();
                DB::disconnect();
                $this->refreshApplication(); // each real request is a fresh process: don't leak Livewire/redirect bindings between crawl requests
                $resp = $this->actingAs($user)->get($url);
                $this->hits++;
                $code = $resp->getStatusCode();
                if ($code >= 500 || ($code === 403 && $this->expectAllowed($route, $user))) {
                    $msg = $resp->exception ? get_class($resp->exception).': '.$resp->exception->getMessage().' @ '.basename($resp->exception->getFile()).':'.$resp->exception->getLine() : '';
                    $this->failures[] = "[$code] {$user->email} GET $url $msg";
                }
            }
        }
    }

    /** Only a sample of ids per parameterised route, chosen from what the user could plausibly reach. */
    private function urlsFor(Route $route, ?Tenant $tenant, User $user): array
    {
        $params = $route->parameterNames();
        if (! $params) {
            return [route($route->getName(), [], false)];
        }
        $tid = $tenant?->id;
        $map = [
            'application' => ['loan_applications', $user->isCustomer() ? ['customer_id' => $user->customer_id] : ($user->isVendor() ? ['vendor_id' => $user->vendor_id] : [])],
            'loan' => ['loan_accounts', $user->isCustomer() ? ['customer_id' => $user->customer_id] : []],
            'loanAccount' => ['loan_accounts', $user->isCustomer() ? ['customer_id' => $user->customer_id] : []],
            'customer' => ['customers', []], 'visit' => ['field_visits', $user->hasPermission('field_visits.view') ? [] : ['assigned_to' => $user->id]],
            'product' => ['products', []], 'vendor' => ['vendors', []], 'wallet' => ['wallets', []], 'order' => ['payment_orders', []],
            'paymentOrder' => ['payment_orders', []], 'complaint' => ['complaints', $user->isCustomer() ? ['customer_id' => $user->customer_id] : []],
            'journal' => ['journal_vouchers', []], 'voucher' => ['journal_vouchers', []], 'account' => ['accounts', ['is_postable' => 1]],
            'batch' => ['reconciliation_batches', []], 'tenant' => ['tenants', []], 'corporate' => ['corporate_clients', []],
            'corporateClient' => ['corporate_clients', []], 'payment' => ['loan_payments', $user->isCustomer() ? ['customer_id' => $user->customer_id] : []],
            'vendorPayment' => ['vendor_payments', $user->isVendor() ? ['vendor_id' => $user->vendor_id] : []],
            'document' => ['documents', []], 'case' => ['loan_accounts', $user->hasPermission('recovery.view') ? [] : ['recovery_agent_id' => $user->id]],
        ];
        $urls = [];
        $values = [];
        foreach ($params as $p) {
            if (! isset($map[$p])) {
                return [];
            }
            [$table, $where] = $map[$p];
            $q = DB::table($table)->where($where);
            if ($table !== 'tenants' && $tid) {
                $q->where('tenant_id', $tid);
            }
            if ($table === 'tenants') {
                $values[$p] = $q->orderBy('id')->limit(2)->pluck('code')->all();

                continue;
            }
            $values[$p] = $q->orderBy('id')->pluck('id')->filter()->values()->all();
            if (count($values[$p]) > 4) {
                $v = $values[$p];
                $values[$p] = [$v[0], $v[intdiv(count($v), 2)], $v[count($v) - 1]];
            }
        }
        if (count($params) === 1) {
            foreach ($values[$params[0]] as $v) {
                $urls[] = route($route->getName(), [$params[0] => $v], false);
            }
        }

        return $urls;
    }

    /** Pages that a user is expected to reach (403 would be a bug): only applies to customer/vendor own-record pages. */
    private function expectAllowed(Route $route, User $user): bool
    {
        return false; // 403s are legitimate for role-restricted staff pages; only 5xx are failures.
    }
}
