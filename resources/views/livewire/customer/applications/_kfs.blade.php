{{-- Key Fact Statement body (DFS §2.4). $t = kfs terms, $loc = 'en'|'ur' --}}
@php($ur = $loc === 'ur')
@php($L = fn ($en, $urText) => $ur ? $urText : $en)
<div dir="{{ $ur ? 'rtl' : 'ltr' }}" class="{{ $ur ? 'font-urdu' : '' }}">
    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ $L('Key Fact Statement', 'کلیدی حقائق کا بیان') }} – {{ $t['institution'] ?? '' }}</p>
    <table class="mt-2 w-full text-sm">
        @foreach([
            [$L('Product', 'پروڈکٹ'), $ur && !empty($t['product_ur']) ? $t['product_ur'] : ($t['product'] ?? '')],
            [$L('Shariah structure', 'شرعی ڈھانچہ'), $t['structure'] ?? ''],
            [$L('Asset', 'اثاثہ'), $t['asset'] ?? '—'],
            [$L('Financed amount', 'فنانس کی گئی رقم'), \App\Support\Money::format($t['financed_amount'] ?? 0)],
            [$L('Disclosed profit rate (per annum)', 'منافع کی شرح (سالانہ)'), ($t['profit_rate'] ?? '').'% ('.($t['profit_method'] ?? '').')'],
            [$L('Annual percentage rate (APR, incl. fees)', 'سالانہ مجموعی شرح (فیس سمیت)'), ($t['apr'] ?? '').'%'],
            [$L('Tenure', 'مدت'), ($t['tenure'] ?? '').' '.(($t['frequency'] ?? 'monthly') === 'weekly' ? $L('weeks', 'ہفتے') : $L('months', 'ماہ'))],
            [$L('Instalment', 'قسط'), \App\Support\Money::format($t['instalment'] ?? 0)],
            [$L('Total profit', 'کل منافع'), \App\Support\Money::format($t['profit_total'] ?? 0)],
            [$L('Charges added to instalments', 'اقساط میں شامل چارجز'), \App\Support\Money::format($t['charges_spread'] ?? 0)],
            [$L('Charges deducted at disbursement', 'ادائیگی پر کٹوتی'), \App\Support\Money::format($t['charges_upfront'] ?? 0)],
            [($t['payout_label'] ?? null) === 'Amount paid to vendor' ? $L('Amount paid to vendor', 'وینڈر کو ادا کی جانے والی رقم') : $L('Net amount disbursed', 'خالص ادا شدہ رقم'), \App\Support\Money::format($t['net_disbursed'] ?? 0)],
            [$L('Total amount payable', 'کل قابل ادائیگی رقم'), \App\Support\Money::format($t['total_payable'] ?? 0)],
            [$L('First instalment due', 'پہلی قسط کی تاریخ'), $t['first_due_date'] ?? ''],
            [$L('Final instalment due', 'آخری قسط کی تاریخ'), $t['maturity_date'] ?? ''],
        ] as [$k, $val])
            <tr class="border-b border-neutral-100"><th class="py-1.5 pe-3 text-start font-medium text-neutral-600">{{ $k }}</th><td class="py-1.5 text-end font-semibold tabular">{{ $val }}</td></tr>
        @endforeach
    </table>
    @if(!empty($t['charges']))
        <p class="mt-3 text-sm font-medium">{{ $L('Fees and charges', 'فیس اور چارجز') }}</p>
        <ul class="list-disc ps-5 text-sm text-neutral-700">
            @foreach($t['charges'] as $c)<li>{{ $c['name'] }}: {{ $c['amount'] !== null ? \App\Support\Money::format($c['amount']) : ($c['basis'] ?? '') }} ({{ str_replace('_', ' ', $c['when']) }})</li>@endforeach
        </ul>
    @endif
    <ul class="mt-3 space-y-1 text-sm text-neutral-700">
        <li>• {{ $ur ? 'تاخیر سے ادائیگی کی رقم خیرات میں دی جاتی ہے اور ادارے کی آمدنی نہیں ہوتی۔' : ($t['late_payment'] ?? '') }}</li>
        <li>• {{ $ur ? 'دستخط کے بعد '.($t['cooling_off_hours'] ?? 24).' گھنٹے کے اندر آپ بغیر کسی چارج کے منسوخ کر سکتے ہیں۔' : 'You may cancel within '.($t['cooling_off_hours'] ?? 24).' hours after signing without any markup or charge.' }}</li>
        <li>• {{ $L('Grievances', 'شکایات') }}: {{ $t['grievance'] ?? '' }}</li>
    </ul>
</div>
