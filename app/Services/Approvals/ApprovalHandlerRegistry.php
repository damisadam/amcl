<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalType;

class ApprovalHandlerRegistry
{
    public const MAP = [
        'loan' => Handlers\LoanApprovalHandler::class,
        'journal_voucher' => Handlers\JournalApprovalHandler::class,
        'vendor_payment' => Handlers\VendorPaymentApprovalHandler::class,
        'expense' => Handlers\ExpenseApprovalHandler::class,
        'fund_transfer' => Handlers\FundTransferApprovalHandler::class,
        'write_off' => Handlers\WriteOffApprovalHandler::class,
        'charity_disbursement' => Handlers\CharityApprovalHandler::class,
        'payroll' => Handlers\PayrollApprovalHandler::class,
        'reconciliation' => Handlers\ReconciliationApprovalHandler::class,
        'journal_reversal' => Handlers\JournalReversalApprovalHandler::class,
    ];

    public function for(ApprovalType $type): ApprovalHandler
    {
        return app(self::MAP[$type->value] ?? throw new \LogicException("No approval handler for {$type->value}"));
    }
}
