<?php

namespace App\Livewire\Customer\Applications;

use App\Enums\ApplicationStatus;
use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\DocumentType;
use App\Models\LoanApplication;
use App\Models\Objection;
use App\Models\SignatureRequest;
use App\Services\Documents\DocumentStore;
use App\Services\Identity\OtpService;
use App\Services\Lending\AgreementService;
use App\Services\Lending\ApplicationService;
use App\Services\Lending\CustomerApplicationActions;
use App\Services\Lending\DisbursementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Application detail: timeline, objections, KFS acknowledgement, e-signature, cooling-off, reapplication. */
#[Layout('layouts.customer')]
class Show extends Component
{
    use CustomerContext, WithFileUploads;

    public LoanApplication $application;

    public string $kfsLocale = 'en';

    public bool $kfsConfirm = false;

    public array $responses = [];

    public $objectionFile;

    public string $otp = '';

    public ?string $demoOtp = null;

    public bool $otpSent = false;

    public string $cancelReason = '';

    public string $withdrawReason = '';

    public function mount(LoanApplication $application)
    {
        $this->application = $this->own($application);
        if ($application->status === ApplicationStatus::Draft) {
            return $this->redirect(route('app.apply', $application->product_id).'?application='.$application->id, navigate: true);
        }
        $this->kfsLocale = app()->getLocale();
        $application->objections()->where('visible_to_customer', true)->whereNull('seen_at')->update(['seen_at' => now()]);
    }

    #[On('realtime')]
    public function refresh(): void {}

    private function fresh(): LoanApplication
    {
        return $this->application = $this->own(LoanApplication::findOrFail($this->application->id));
    }

    public function respond(int $objectionId, CustomerApplicationActions $actions, DocumentStore $store): void
    {
        $this->validate(["responses.$objectionId" => 'required|string|min:3|max:2000', 'objectionFile' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120']);
        $o = Objection::where('loan_application_id', $this->application->id)->findOrFail($objectionId);
        $this->attempt(function () use ($o, $actions, $store, $objectionId) {
            if ($this->objectionFile) {
                $store->store($this->objectionFile, $this->application, DocumentType::where('code', 'JV_SUPPORT')->first(), $this->user(), __('Response to objection #:id', ['id' => $o->id]));
            }
            $actions->respondToObjection($o, $this->user(), $this->responses[$objectionId]);
            unset($this->responses[$objectionId]);
            $this->objectionFile = null;
        }, __('Response sent.'));
    }

    public function resubmit(ApplicationService $svc): void
    {
        $open = $this->fresh()->objections()->where('visible_to_customer', true)->where('status', 'open')->count();
        if ($open) {
            $this->addError('action', trans_choice('{1} Please respond to the open request before resubmitting.|[2,*] Please respond to all :count open requests before resubmitting.', $open, ['count' => $open]));

            return;
        }
        $this->attempt(fn () => $svc->submit($this->fresh(), $this->user()), __('Application resubmitted.'));
    }

    public function withdraw(ApplicationService $svc): void
    {
        $this->validate(['withdrawReason' => 'required|string|min:3|max:300']);
        $this->attempt(fn () => $svc->withdraw($this->fresh(), $this->user(), $this->withdrawReason), __('Application withdrawn.'));
    }

    public function acknowledgeKfs(AgreementService $agreements): void
    {
        $this->validate(['kfsConfirm' => 'accepted', 'kfsLocale' => 'in:en,ur'], ['kfsConfirm.accepted' => __('Please confirm you have read and understood the Key Fact Statement.')]);
        $this->attempt(fn () => $agreements->acknowledgeKfs($this->fresh(), $this->user(), $this->kfsLocale, request()->ip(), request()->userAgent()),
            __('Key Fact Statement acknowledged. Please sign your documents.'));
    }

    /** Applicant documents awaiting a digital signature that the customer has not opened yet (QA C4). */
    private function unread(): \Illuminate\Support\Collection
    {
        return SignatureRequest::with('documentType')->where('loan_application_id', $this->application->id)->where('signer_type', 'applicant')
            ->where('signing_method', 'digital')->whereIn('status', ['sent', 'pending'])->whereNull('viewed_at')->get();
    }

    public function sendSignOtp(OtpService $otp): void
    {
        if (($unread = $this->unread())->isNotEmpty()) {
            $this->addError('otp', __('Please open and read: :d.', ['d' => $unread->map(fn ($r) => __($r->documentType->name))->join(', ')]));

            return;
        }
        $this->attempt(function () use ($otp) {
            $this->demoOtp = $otp->issue($this->user()->mobile, 'esign');
            $this->otpSent = true;
        }, __('Signing code sent to your mobile.'));
    }

    public function signAll(OtpService $otp, AgreementService $agreements): void
    {
        $this->validate(['otp' => 'required|digits:6'], [], ['otp' => __('signing code')]);
        if (($unread = $this->unread())->isNotEmpty()) {
            $this->addError('otp', __('Please open and read: :d.', ['d' => $unread->map(fn ($r) => __($r->documentType->name))->join(', ')]));

            return;
        }
        $app = $this->fresh();
        try {
            $otp->verify($this->user()->mobile, 'esign', $this->otp);
        } catch (\App\Exceptions\DomainException $e) {
            $this->addError('otp', $e->getMessage()); // QA M6: inline, with attempts left

            return;
        }
        $this->attempt(function () use ($agreements, $app) {
            $pending = SignatureRequest::where('loan_application_id', $app->id)->where('signer_type', 'applicant')->where('signing_method', 'digital')
                ->whereIn('status', ['sent', 'pending'])->get();
            foreach ($pending as $req) {
                // Each document is signed exactly as the version the customer opened.
                $agreements->sign($req, $this->user(), request()->ip(), request()->userAgent(), $req->viewed_hash);
            }
            $this->reset('otp', 'otpSent', 'demoOtp');
        }, __('Documents signed electronically.'));
    }

    public function cancel(AgreementService $agreements): void
    {
        $this->validate(['cancelReason' => 'required|string|min:3|max:300']);
        $this->attempt(fn () => $agreements->cancelDuringCoolingOff($this->fresh(), $this->user(), $this->cancelReason), __('Financing cancelled. No charge has been applied.'));
    }

    public function waive(AgreementService $agreements): void
    {
        $this->attempt(fn () => $agreements->waiveCoolingOff($this->fresh(), $this->user()), __('Cooling-off waived. Your case is queued for disbursement.'));
    }

    public function reapply(ApplicationService $svc)
    {
        $new = $this->attempt(fn () => $svc->reapply($this->fresh(), $this->user()), __('A new draft was created from this application.'));
        if ($new instanceof LoanApplication) {
            return $this->redirect(route('app.apply', $new->product_id).'?application='.$new->id, navigate: true);
        }
    }

    public function render()
    {
        $app = $this->fresh();
        if ($app->status === ApplicationStatus::CoolingOff && $app->isCoolingOffElapsed()) {
            app(AgreementService::class)->completeCoolingOff($app);
            $app = $this->fresh();
        }
        $app->load(['product', 'productVersion', 'vendor', 'guarantors', 'kfs', 'signatureRequests.documentType', 'loanAccount']);

        return view('livewire.customer.applications.show', [
            'app' => $app,
            'history' => $app->histories()->where('visible_to_customer', true)->get(),
            'objections' => $app->objections()->where('visible_to_customer', true)->get(),
            'signatures' => $app->signatureRequests,
            'unread' => $this->unread(),
            'kfs' => $app->kfs,
            'disbursement' => $app->disbursements()->latest('id')->first(),
            'documents' => $app->documents()->with('type')->where('status', '!=', 'superseded')->latest()->get(),
        ])->title(__('Application').' '.$this->application->application_no);
    }
}
