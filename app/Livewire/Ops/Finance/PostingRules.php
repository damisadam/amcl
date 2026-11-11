<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\JournalVoucher;
use App\Models\PostingRule;
use App\Services\Accounting\FinanceAdminService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Sub-ledger posting rules: business event → journal lines (tenant-configurable, FMS §2). */
#[Layout('layouts.ops')]
class PostingRules extends Component
{
    use HandlesActions;

    public string $event = '';

    public array $lines = [];

    public string $newEvent = '';

    public function mount(): void
    {
        $this->need('fms.view', 'fms.posting_rules.manage');
        $this->select(PostingRule::orderBy('event_type')->value('event_type') ?? 'loan_disbursement');
    }

    public function select(string $event): void
    {
        $this->event = $event;
        $this->lines = PostingRule::where('event_type', $event)->orderBy('line_no')->get()
            ->map(fn ($r) => ['side' => $r->side, 'account_id' => (string) $r->account_id, 'amount_key' => $r->amount_key, 'description' => $r->description, 'is_active' => $r->is_active])->all();
        $this->resetErrorBag();
    }

    public function addLine(): void
    {
        $this->need('fms.posting_rules.manage');
        $this->lines[] = ['side' => 'debit', 'account_id' => '', 'amount_key' => 'amount', 'description' => '', 'is_active' => true];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function createEvent(): void
    {
        $this->need('fms.posting_rules.manage');
        $this->validate(['newEvent' => ['required', 'regex:/^[a-z0-9_]{3,50}$/']]);
        $this->event = $this->newEvent;
        $this->lines = [['side' => 'debit', 'account_id' => '', 'amount_key' => 'amount', 'description' => '', 'is_active' => true],
            ['side' => 'credit', 'account_id' => '', 'amount_key' => 'amount', 'description' => '', 'is_active' => true]];
        $this->newEvent = '';
    }

    public function save(): void
    {
        $this->need('fms.posting_rules.manage');
        $this->attempt(fn () => app(FinanceAdminService::class)->savePostingRules($this->event, $this->lines, auth()->user()), 'Posting rules saved.');
    }

    public function render()
    {
        $events = collect(array_keys(config('posting_rules')))->merge(PostingRule::distinct()->pluck('event_type'))->unique()->sort()->values();

        return view('livewire.ops.finance.posting-rules', [
            'events' => $events,
            'system' => array_keys(config('posting_rules')),
            'usage' => JournalVoucher::whereNotNull('event_type')->selectRaw('event_type, COUNT(*) n')->groupBy('event_type')->pluck('n', 'event_type')->all(),
            'accounts' => Account::where('is_postable', true)->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all(),
            'canEdit' => auth()->user()->hasPermission('fms.posting_rules.manage'),
        ]);
    }
}
