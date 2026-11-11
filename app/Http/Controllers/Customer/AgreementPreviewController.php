<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\LoanApplication;
use App\Models\SignatureRequest;
use App\Services\Lending\AgreementService;
use Illuminate\Http\Request;

/**
 * QA C4: the customer opens the exact document they are about to e-sign. Opening it records the time and the
 * SHA-256 of the version shown; signing later refuses if the signed version differs.
 */
class AgreementPreviewController extends Controller
{
    public function __invoke(Request $request, LoanApplication $application, SignatureRequest $signatureRequest, AgreementService $agreements)
    {
        $user = $request->user();
        abort_unless((int) $application->customer_id === (int) $user->customer_id, 404);
        abort_unless((int) $signatureRequest->loan_application_id === (int) $application->id && $signatureRequest->signer_type === 'applicant', 404);
        abort_unless($signatureRequest->signing_method === 'digital', 404);

        if (in_array($signatureRequest->status, ['sent', 'pending'], true)) {
            $agreements->markViewed($signatureRequest, $user);
        }

        return response($agreements->guarantorDocumentHtml($signatureRequest), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
