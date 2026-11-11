<?php

namespace App\Livewire\Customer\Support;

use App\Enums\ComplaintCategory;
use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\Complaint;
use App\Models\ContentItem;
use App\Models\LoanAccount;
use App\Models\WalletTransaction;
use App\Services\Support\ComplaintService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Grievance redressal (DFS §5.3) incl. transaction disputes & refund requests (Wallet §10). */
#[Layout('layouts.customer')]
class Index extends Component
{
    use CustomerContext;

    #[Url]
    public ?int $transaction = null;

    public string $category = 'general';

    public string $subject = '';

    public string $description = '';

    public string $related = '';

    public bool $creating = false;

    public function mount(): void
    {
        if ($this->transaction) {
            $this->ownTxn($this->transaction);
            $this->creating = true;
            $this->category = 'transaction_dispute';
            $this->related = 'wallet:'.$this->transaction;
        }
    }

    private function ownTxn(int $id): WalletTransaction
    {
        $w = $this->wallet() ?? abort(404);

        return WalletTransaction::where('wallet_id', $w->id)->findOrFail($id);
    }

    public function submit(ComplaintService $svc)
    {
        $this->validate([
            'category' => 'required|in:'.implode(',', ComplaintCategory::values()),
            'subject' => 'required|string|min:5|max:150',
            'description' => 'required|string|min:10|max:3000',
            'related' => in_array($this->category, ['transaction_dispute', 'refund_request'], true) ? 'required|string' : 'nullable|string',
        ], ['related.required' => __('Select the transaction this is about.')]);

        $model = null;
        if ($this->related) {
            [$kind, $id] = explode(':', $this->related) + [null, null];
            $model = match ($kind) {
                'wallet' => $this->ownTxn((int) $id),
                'loan' => $this->own(LoanAccount::find((int) $id)),
                default => null,
            };
        }
        $c = $this->attempt(fn () => $svc->open($this->customer(), ComplaintCategory::from($this->category), $this->subject, $this->description, $model, 'web', $this->user()));
        if ($c instanceof Complaint) {
            session()->flash('success', __('Complaint :t registered.', ['t' => $c->ticket_no]));

            return $this->redirect(route('app.support.show', $c), navigate: true);
        }
    }

    public function render()
    {
        $c = $this->customer();
        $w = $c->wallet()->first();

        return view('livewire.customer.support.index', [
            'complaints' => Complaint::where('customer_id', $c->id)->latest('id')->get(),
            'categories' => collect(ComplaintCategory::cases())->mapWithKeys(fn ($x) => [$x->value => __($x->label())])->all(),
            'txns' => $w ? WalletTransaction::where('wallet_id', $w->id)->latest('id')->take(30)->get() : collect(),
            'loans' => LoanAccount::where('customer_id', $c->id)->get(),
            'faqs' => ContentItem::where('type', 'faq')->where('is_active', true)->orderBy('sort')->get(),
            'days' => \App\Services\Tenancy\TenantSettings::get('complaints', 'resolution_days'),
        ])->title(__('Complaints & Support'));
    }
}
