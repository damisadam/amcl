<?php

namespace App\Services\Reports;

use App\Models\Complaint;
use App\Models\LoanAccount;
use App\Models\LoanProvision;
use App\Models\PaymentOrder;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;

/**
 * Regulatory-style returns. FORMATS ARE SIMULATED: official SECP/SBP and Raast Annexure-A templates
 * must be confirmed before production (docs/DECISIONS.md).
 */
class RegulatoryReports
{
    public const DISCLAIMER = 'Simulated format – confirm official SECP / SBP / Raast templates before regulatory submission.';

    public function __construct(private array $f = []) {}

    private function from(): string { return $this->f['from'] ?? now()->startOfYear()->toDateString(); }
    private function to(): string { return $this->f['to'] ?? today()->toDateString(); }

    public function portfolioQuality(): array
    {
        $rates = TenantSettings::get('lending', 'provisioning_rates');
        $rows = LoanAccount::whereIn('status', ['active', 'overdue'])->selectRaw('classification, COUNT(*) n, SUM(principal_outstanding) p')->groupBy('classification')->get()->keyBy('classification');
        $latestProv = LoanProvision::whereIn('loan_provisions.id', LoanProvision::selectRaw('MAX(id)')->groupBy('loan_account_id'))
            ->join('loan_accounts', 'loan_accounts.id', '=', 'loan_provisions.loan_account_id')->whereIn('loan_accounts.status', ['active', 'overdue'])
            ->selectRaw('loan_accounts.classification c, SUM(loan_provisions.required_provision) v')->groupBy('c')->pluck('v', 'c');
        $out = [];
        foreach (['regular', 'oaem', 'substandard', 'doubtful', 'loss'] as $c) {
            $p = Money::of($rows[$c]->p ?? 0);
            $out[] = ['classification' => $c, 'count' => (int) ($rows[$c]->n ?? 0), 'outstanding' => $p, 'rate' => $rates[$c] ?? 0,
                'required' => Money::pct($p, (string) ($rates[$c] ?? 0)), 'held' => Money::of($latestProv[$c] ?? 0)];
        }

        return $out;
    }

    public function productWise(): array
    {
        return LoanAccount::join('products', 'products.id', '=', 'loan_accounts.product_id')
            ->selectRaw("products.name p, loan_accounts.shariah_structure s, COUNT(*) n,
                SUM(CASE WHEN loan_accounts.status IN ('active','overdue') THEN loan_accounts.principal_outstanding ELSE 0 END) o,
                SUM(loan_accounts.principal) d")
            ->groupBy('p', 's')->get()->map(fn ($r) => ['product' => $r->p, 'structure' => self::v($r->s), 'accounts' => (int) $r->n, 'disbursed' => Money::of($r->d), 'outstanding' => Money::of($r->o)])->all();
    }

    public function outreach(): array
    {
        $base = LoanAccount::join('customers', 'customers.id', '=', 'loan_accounts.customer_id');

        return [
            'gender' => (clone $base)->selectRaw("COALESCE(customers.gender, 'unspecified') g, COUNT(DISTINCT customers.id) n, SUM(loan_accounts.principal) amt")->groupBy('g')->get()
                ->map(fn ($r) => ['gender' => $r->g, 'borrowers' => (int) $r->n, 'disbursed' => Money::of($r->amt)])->all(),
            'city' => (clone $base)->selectRaw("COALESCE(customers.city, '—') c, COUNT(DISTINCT customers.id) n, SUM(loan_accounts.principal) amt")->groupBy('c')->orderByDesc('n')->get()
                ->map(fn ($r) => ['city' => $r->c, 'borrowers' => (int) $r->n, 'disbursed' => Money::of($r->amt)])->all(),
        ];
    }

    /** Raast Annexure-A style: counts & values by month and purpose for Raast orders (simulated rail). */
    public function raastAnnexure(): array
    {
        return PaymentOrder::where('provider', 'raast')->where('status', 'succeeded')->whereBetween('settled_at', [$this->from(), $this->to().' 23:59:59'])
            ->selectRaw("DATE_FORMAT(settled_at, '%Y-%m') m, purpose, direction, COUNT(*) n, SUM(amount) amt")->groupBy('m', 'purpose', 'direction')->orderBy('m')->get()
            ->map(fn ($r) => ['month' => $r->m, 'purpose' => self::v($r->purpose), 'direction' => $r->direction, 'count' => (int) $r->n, 'value' => Money::of($r->amt)])->all();
    }

    public function complaints(): array
    {
        $q = fn () => Complaint::whereBetween('created_at', [$this->from(), $this->to().' 23:59:59']);
        $resolved = $q()->whereNotNull('resolved_at')->get(['resolved_at', 'sla_due_at']);

        return [
            'received' => $q()->count(),
            'resolved' => $resolved->count(),
            'within_sla' => $resolved->filter(fn ($c) => ! $c->sla_due_at || $c->resolved_at->lte($c->sla_due_at))->count(),
            'open' => $q()->whereNull('resolved_at')->count(),
            'by_category' => $q()->selectRaw('category, COUNT(*) n')->groupBy('category')->pluck('n', 'category')->all(),
        ];
    }

    private static function v(mixed $x): mixed
    {
        return $x instanceof \BackedEnum ? $x->value : $x;
    }
}
