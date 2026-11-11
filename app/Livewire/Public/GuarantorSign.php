<?php

namespace App\Livewire\Public;

use App\Exceptions\DomainException;
use App\Models\Guarantor;
use App\Models\SignatureRequest;
use App\Models\Tenant;
use App\Services\Identity\OtpService;
use App\Services\Lending\AgreementService;
use App\Support\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Guarantor remote e-signature (H6). Reached only through a time-limited signed URL sent to the guarantor's
 * mobile; the guarantor reads the full document, confirms with an OTP to the same mobile, then signs. The
 * signature is bound to the SHA-256 of exactly the document shown.
 */
#[Layout('layouts.guest')]
class GuarantorSign extends Component
{
    #[Locked]
    public int $tenantId;

    #[Locked]
    public int $requestId;

    #[Locked]
    public string $viewedHash = '';

    public bool $read = false;

    public bool $codeSent = false;

    public string $otp = '';

    public ?string $demoOtp = null;

    public bool $done = false;

    public function mount(Tenant $tenant, int $signatureRequest): void
    {
        abort_unless($tenant->isActive(), 404);
        $this->tenantId = $tenant->id;
        session(['entry_tenant_id' => $tenant->id]);
        TenantContext::set($tenant);
        $req = SignatureRequest::where('signer_type', 'guarantor')->findOrFail($signatureRequest);
        $this->requestId = $req->id;
        $this->done = $req->status === 'signed';
    }

    public function hydrate(): void
    {
        TenantContext::set(Tenant::findOrFail($this->tenantId));
    }

    private function req(): SignatureRequest
    {
        return SignatureRequest::with(['application.customer', 'application.tenant', 'documentType'])->findOrFail($this->requestId);
    }

    private function guarantor(SignatureRequest $req): Guarantor
    {
        return Guarantor::where('loan_application_id', $req->loan_application_id)->findOrFail($req->signer_id);
    }

    public function confirmRead(): void
    {
        $this->read = true;
    }

    public function sendCode(OtpService $otp): void
    {
        if (! $this->read) {
            $this->addError('otp', __('Read the document and tick the confirmation first.'));

            return;
        }
        $g = $this->guarantor($this->req());
        try {
            $this->demoOtp = $otp->issue($g->mobile, 'guarantor_esign');
            $this->codeSent = true;
        } catch (DomainException $e) {
            $this->addError('otp', $e->getMessage());
        }
    }

    public function sign(OtpService $otp, AgreementService $agreements): void
    {
        $this->validate(['otp' => 'required|digits:6'], [], ['otp' => __('verification code')]);
        $req = $this->req();
        try {
            $otp->verify($this->guarantor($req)->mobile, 'guarantor_esign', $this->otp);
            $agreements->signAsGuarantor($req, $this->viewedHash, request()->ip(), substr((string) request()->userAgent(), 0, 500));
            $this->done = true;
        } catch (DomainException $e) {
            $this->addError('otp', $e->getMessage());
        }
    }

    public function render(AgreementService $agreements)
    {
        $req = $this->req();
        $html = $this->done ? null : $agreements->guarantorDocumentHtml($req);
        if ($html !== null) {
            $this->viewedHash = hash('sha256', $html);
        }
        view()->share('currentTenant', $req->application->tenant);

        return view('livewire.public.guarantor-sign', ['req' => $req, 'html' => $html, 'tenant' => $req->application->tenant,
            'mobile' => '••••••'.substr($this->guarantor($req)->mobile, -4)]);
    }
}
