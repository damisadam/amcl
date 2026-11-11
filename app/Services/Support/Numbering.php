<?php

namespace App\Services\Support;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Per-tenant sequential document numbers, e.g. APP-2026-000123. */
class Numbering
{
    public const PREFIXES = [
        'customer' => 'CIF', 'application' => 'APP', 'loan_account' => 'LN', 'disbursement' => 'DSB', 'loan_payment' => 'RPY',
        'receipt' => 'RCT', 'wallet' => 'WLT', 'wallet_txn' => 'WTX', 'payment_order' => 'PO', 'journal' => 'JV', 'vendor' => 'VND',
        'vendor_bill' => 'BILL', 'vendor_payment' => 'VP', 'complaint' => 'TKT', 'lead' => 'LD', 'reconciliation' => 'REC',
        'suspense' => 'SUS', 'noc' => 'NOC', 'write_off' => 'WO', 'fund_transfer' => 'FT', 'expense' => 'EXP', 'charity' => 'CHR',
        'bill_payment' => 'BP', 'portfolio_transfer' => 'PT', 'receivable' => 'AR', 'asset' => 'FA', 'merchant' => 'MRC',
    ];

    public static function next(string $key, ?int $tenantId = null, bool $withYear = true, int $pad = 6): string
    {
        $tenantId ??= TenantContext::id() ?? throw new \RuntimeException('Numbering requires a tenant context.');

        $value = DB::transaction(function () use ($key, $tenantId) {
            $row = DB::table('number_sequences')->where('tenant_id', $tenantId)->where('key', $key)->lockForUpdate()->first();
            if (! $row) {
                DB::table('number_sequences')->insertOrIgnore(['tenant_id' => $tenantId, 'key' => $key, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('number_sequences')->where('tenant_id', $tenantId)->where('key', $key)->lockForUpdate()->first();
            }
            DB::table('number_sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return $row->next_value;
        });

        $prefix = self::PREFIXES[$key] ?? strtoupper($key);

        return $prefix.'-'.($withYear ? now()->format('Y').'-' : '').str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
    }
}
