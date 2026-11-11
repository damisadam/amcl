<?php

namespace App\Services\Lending;

use App\Enums\ShariahStructure;
use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Documents\DocumentStore;
use Illuminate\Http\UploadedFile;

/**
 * Murabaha sequence controls (QA F7): the institution must own the asset (vendor invoice issued in the
 * institution's name, verified by a second user) before the customer is offered the Murabaha sale and signs.
 */
class MurabahaService
{
    public const BASE_PACK = ['FIN_AGREEMENT', 'UNDERTAKING', 'DPN', 'AFFIDAVIT'];

    public const MURABAHA_PACK = ['PURCHASE_REQUISITION', 'MURABAHA_OFFER'];

    public function __construct(private DocumentStore $documents) {}

    /** Vendor-paid Murabaha financings need proof the institution bought the asset before the sale is signed. */
    public function requiresAssetOwnership(LoanApplication $app): bool
    {
        return $app->product->shariah_structure === ShariahStructure::Murabaha && $app->productVersion->disbursement_mode === 'vendor';
    }

    public function assetOwnershipConfirmed(LoanApplication $app): bool
    {
        return ! $this->requiresAssetOwnership($app) || $this->invoice($app)?->status === 'verified';
    }

    public function invoice(LoanApplication $app): ?Document
    {
        $type = DocumentType::where('code', 'VENDOR_INVOICE')->value('id');

        return $type ? $app->documents()->where('document_type_id', $type)->where('status', '!=', 'superseded')->latest('id')->first() : null;
    }

    /** Maker: record the vendor invoice issued in the institution's name. */
    public function recordVendorInvoice(LoanApplication $app, UploadedFile $file, User $by, string $invoiceRef): Document
    {
        if (! $this->requiresAssetOwnership($app)) {
            throw new DomainException('Asset ownership evidence is only required for vendor-paid Murabaha financings.');
        }
        $type = DocumentType::where('code', 'VENDOR_INVOICE')->firstOrFail();
        $doc = $this->documents->store($file, $app, $type, $by, 'Vendor invoice '.$invoiceRef.' (in institution\'s name)');
        Auditor::record('murabaha.invoice_recorded', $app, [], ['invoice_ref' => $invoiceRef, 'document_id' => $doc->id]);

        return $doc;
    }

    /** Checker: confirm the institution owns the asset. The uploader cannot confirm their own evidence. */
    public function confirmOwnership(LoanApplication $app, User $checker): Document
    {
        $doc = $this->invoice($app) ?? throw new DomainException('Record the vendor invoice first.');
        if ((int) $doc->uploaded_by === (int) $checker->id) {
            throw new DomainException('Segregation of duties: another user must confirm the asset ownership evidence.');
        }
        $this->documents->verify($doc, $checker, true);
        Auditor::record('murabaha.asset_ownership_confirmed', $app, [], ['document_id' => $doc->id]);
        app(\App\Services\Notifications\Notifier::class)->toCustomer($app->customer, 'murabaha_ready', [
            'title' => 'Your Murabaha offer is ready', 'body' => $app->tenant->name.' has purchased your '.($app->asset_description ?: 'asset').'. Review the Key Fact Statement and sign to continue.',
            'url' => '/app/applications/'.$app->id,
        ]);

        return $doc->fresh();
    }

    /** Document codes to be signed for this financing: Murabaha cases add requisition/agency and offer & acceptance. */
    public function pack(LoanApplication $app): array
    {
        return $app->product->shariah_structure === ShariahStructure::Murabaha ? array_merge(self::MURABAHA_PACK, self::BASE_PACK) : self::BASE_PACK;
    }

    /** KFS label for the payout line. */
    public function payoutLabel(LoanApplication $app): string
    {
        return $app->productVersion->disbursement_mode === 'vendor' ? 'Amount paid to vendor' : 'Net amount disbursed';
    }
}
