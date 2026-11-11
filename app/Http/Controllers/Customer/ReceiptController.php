<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\LoanPayment;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;

/** Printable receipts for the logged-in customer's own payments (ownership enforced). */
class ReceiptController extends Controller
{
    public function loan(Request $request, LoanPayment $payment)
    {
        abort_unless((int) $payment->customer_id === (int) $request->user()->customer_id, 404);
        abort_unless(in_array($payment->status->value, ['succeeded', 'reversed'], true), 404);
        $payment->load(['loanAccount.product', 'customer', 'allocations.instalment']);

        return view('documents.receipt', ['p' => $payment, 'tenant' => $payment->tenant, 'kind' => 'loan']);
    }

    public function wallet(Request $request, WalletTransaction $transaction)
    {
        $wallet = $transaction->wallet;
        abort_unless($wallet->owner_type === (new \App\Models\Customer)->getMorphClass() && (int) $wallet->owner_id === (int) $request->user()->customer_id, 404);
        $transaction->load(['wallet', 'paymentOrder']);

        return view('documents.receipt', ['p' => $transaction, 'tenant' => $transaction->tenant, 'kind' => 'wallet']);
    }
}
