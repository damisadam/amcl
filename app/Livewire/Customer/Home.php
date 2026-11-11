<?php

namespace App\Livewire\Customer;

use App\Enums\ApplicationStatus;
use App\Enums\KycStatus;
use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\ContentItem;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
use App\Models\LoanPayment;
use App\Models\WalletTransaction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.customer')]
class Home extends Component
{
    use CustomerContext;

    public function mount()
    {
        $c = $this->customer();
        if (! $this->user()->mobile_verified_at || $c->kyc_status === KycStatus::NotStarted) {
            return $this->redirect(route('app.onboarding'), navigate: true);
        }
    }

    /**
     * The single most important thing for this customer to do now (QA UX "next step" home):
     * KYC → answer a request → acknowledge KFS → sign → cooling-off → overdue/next instalment → finish a draft → explore.
     * @return array{title:string, body:string, label:string, url:string, tone:string}
     */
    private function nextStep($c, $actionable, $loans): array
    {
        if ($c->kyc_status !== KycStatus::Passed) {
            return match ($c->kyc_status) {
                KycStatus::Failed => ['title' => __('We could not verify your identity'), 'body' => __('Please visit a branch with your original CNIC, or contact support.'), 'label' => __('Contact support'), 'url' => route('app.support'), 'tone' => 'red'],
                KycStatus::PendingReview => ['title' => __('Your verification is under review'), 'body' => __('Our team is checking your details. We will notify you as soon as it is done.'), 'label' => __('View status'), 'url' => route('app.onboarding'), 'tone' => 'amber'],
                default => ['title' => __('Complete your identity verification'), 'body' => __('Verify your CNIC and selfie to apply for financing and activate your wallet.'), 'label' => __('Continue verification'), 'url' => route('app.onboarding'), 'tone' => 'amber'],
            };
        }
        $byStatus = fn (ApplicationStatus $s) => $actionable->first(fn ($a) => $a->status === $s);
        if ($a = $byStatus(ApplicationStatus::Returned)) {
            return ['title' => __('Your application needs your response'), 'body' => __(':no – our team asked for more information.', ['no' => $a->application_no]), 'label' => __('Respond now'), 'url' => route('app.applications.show', $a), 'tone' => 'amber'];
        }
        if ($a = $byStatus(ApplicationStatus::Approved)) {
            return ['title' => __('Your financing is approved'), 'body' => __('Read and acknowledge your Key Fact Statement for :no.', ['no' => $a->application_no]), 'label' => __('Review KFS'), 'url' => route('app.applications.show', $a), 'tone' => 'green'];
        }
        if ($a = $byStatus(ApplicationStatus::KfsAcknowledged)) {
            return ['title' => __('Sign your financing documents'), 'body' => __('Read each document, then sign with a one-time code.'), 'label' => __('Read & sign'), 'url' => route('app.applications.show', $a), 'tone' => 'green'];
        }
        if ($a = $byStatus(ApplicationStatus::CoolingOff)) {
            return ['title' => __('Cooling-off period'), 'body' => __('You can cancel free of charge until :t. After that we disburse.', ['t' => $a->cooling_off_ends_at?->format('d M H:i')]), 'label' => __('View'), 'url' => route('app.applications.show', $a), 'tone' => 'blue'];
        }
        $due = $loans->map(fn ($l) => [$l, $l->nextInstalment()])->filter(fn ($x) => $x[1])->sortBy(fn ($x) => $x[1]->due_date)->first();
        if ($due) {
            [$loan, $inst] = $due;
            $amount = \App\Support\Money::sub(\App\Support\Money::add($inst->total_due, $inst->late_charge_due), $inst->total_paid);
            $days = (int) today()->diffInDays($inst->due_date, false);
            $when = $days < 0 ? __('overdue by :n day(s)', ['n' => -$days]) : ($days === 0 ? __('due today') : __('due in :n day(s)', ['n' => $days]));

            return ['title' => __('Instalment :when', ['when' => $when]), 'body' => \App\Support\Money::format($amount).' · '.$loan->product->localizedName().' · '.$inst->due_date->format('d M Y'),
                'label' => __('Pay now'), 'url' => route('app.pay').'?loan='.$loan->id, 'tone' => $days < 0 ? 'red' : ($days <= 5 ? 'amber' : 'green')];
        }
        if ($a = $byStatus(ApplicationStatus::Draft)) {
            return ['title' => __('Finish your application'), 'body' => __('Your :p application is saved as a draft.', ['p' => $a->product->localizedName()]), 'label' => __('Continue'), 'url' => route('app.apply', $a->product_id).'?application='.$a->id, 'tone' => 'blue'];
        }

        return ['title' => __('Own what you need, the halal way'), 'body' => __('Explore Shariah-compliant financing for bikes, rickshaws, laptops, solar and more.'), 'label' => __('Browse products'), 'url' => route('app.products'), 'tone' => 'green'];
    }

    #[On('realtime')]
    public function refresh(): void {}

    public function render()
    {
        $c = $this->customer();
        $wallet = $c->wallet()->first();
        $loans = LoanAccount::where('customer_id', $c->id)->whereIn('status', ['active', 'overdue'])->with('product')->get();
        $actionable = LoanApplication::where('customer_id', $c->id)->with('product')
            ->whereIn('status', [ApplicationStatus::Draft, ApplicationStatus::Returned, ApplicationStatus::Approved, ApplicationStatus::KfsAcknowledged, ApplicationStatus::CoolingOff])
            ->latest()->get();
        $inProgress = LoanApplication::where('customer_id', $c->id)->with('product')
            ->whereIn('status', [ApplicationStatus::Submitted, ApplicationStatus::UnderReview, ApplicationStatus::PendingApproval, ApplicationStatus::ReadyForDisbursement, ApplicationStatus::DisbursementInProgress])
            ->latest()->get();
        $recent = $wallet ? WalletTransaction::where('wallet_id', $wallet->id)->latest('id')->take(5)->get() : collect();
        $payments = LoanPayment::where('customer_id', $c->id)->latest('id')->take(3)->get();

        $next = $this->nextStep($c, $actionable, $loans);
        $tracked = $actionable->first(fn ($a) => $a->status !== ApplicationStatus::Draft) ?? $inProgress->first();

        return view('livewire.customer.home', [
            'next' => $next,
            'tracked' => $tracked,
            'customer' => $c,
            'wallet' => $wallet,
            'loans' => $loans,
            'actionable' => $actionable,
            'inProgress' => $inProgress,
            'recent' => $recent,
            'payments' => $payments,
            'banners' => ContentItem::whereIn('type', ['banner', 'notice'])->where('is_active', true)->orderBy('sort')->take(3)->get(),
        ])->title(__('Home'));
    }
}
