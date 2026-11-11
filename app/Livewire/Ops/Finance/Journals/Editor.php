<?php

namespace App\Livewire\Ops\Finance\Journals;

use App\Enums\JournalStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\DocumentType;
use App\Models\JournalVoucher;
use App\Services\Accounting\GeneralLedger;
use App\Services\Accounting\JournalWorkflow;
use App\Services\Documents\DocumentStore;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Manual journal voucher editor (maker). */
#[Layout('layouts.ops')]
class Editor extends Component
{
    use HandlesActions, WithFileUploads;

    public ?JournalVoucher $journal = null;

    public string $voucher_date = '';

    public string $narration = '';

    public ?string $branch_id = null;

    public array $lines = [];

    public array $attachments = [];

    public function mount(?JournalVoucher $journal = null): void
    {
        $this->need('fms.journals.create');
        $this->voucher_date = today()->toDateString();
        if ($journal?->exists) {
            abort_unless($journal->origin === 'manual' && in_array($journal->status, [JournalStatus::Draft, JournalStatus::Rejected], true), 403, 'Only manual draft or rejected vouchers can be edited.');
            abort_unless((int) $journal->created_by === (int) auth()->id(), 403, 'Only the maker can edit this voucher.');
            $this->journal = $journal;
            $this->voucher_date = $journal->voucher_date->toDateString();
            $this->narration = $journal->narration;
            $this->branch_id = $journal->branch_id ? (string) $journal->branch_id : null;
            $this->lines = $journal->lines->map(fn ($l) => [
                'account_id' => (string) $l->account_id, 'debit' => Money::isZero($l->debit) ? '' : (string) $l->debit, 'credit' => Money::isZero($l->credit) ? '' : (string) $l->credit,
                'description' => $l->description, 'cost_center_id' => (string) $l->cost_center_id, 'branch_id' => (string) $l->branch_id,
            ])->all();
        } else {
            $this->journal = null;
            $this->lines = [$this->blankLine(), $this->blankLine()];
        }
    }

    private function blankLine(): array
    {
        return ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => '', 'cost_center_id' => '', 'branch_id' => ''];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->blankLine();
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function save(bool $submit = false)
    {
        $this->need('fms.journals.create');
        $this->validate([
            'voucher_date' => 'required|date',
            'narration' => 'required|string|min:5|max:1000',
            'lines.*.account_id' => 'nullable|integer',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'attachments.*' => 'file|max:5120|mimes:jpg,jpeg,png,webp,pdf',
        ]);
        $lines = array_map(fn ($l) => array_map(fn ($v) => $v === '' ? null : $v, $l), $this->lines);
        $user = auth()->user();

        $jv = $this->attempt(function () use ($lines, $user, $submit) {
            $gl = app(GeneralLedger::class);
            $header = ['voucher_date' => $this->voucher_date, 'narration' => $this->narration, 'branch_id' => $this->branch_id ?: null];
            $jv = $this->journal ? $gl->updateDraft($this->journal, $header, $lines) : $gl->createManual($header, $lines, $user);
            foreach ($this->attachments as $file) {
                app(DocumentStore::class)->store($file, $jv, DocumentType::where('code', 'JV_SUPPORT')->first(), $user);
            }
            if ($submit) {
                app(JournalWorkflow::class)->submit($jv->fresh('lines'), $user);
            }

            return $jv;
        }, $submit ? 'Voucher submitted.' : 'Draft saved.');

        if ($jv) {
            return $this->redirect(route('ops.finance.journals.show', $jv), navigate: true);
        }
    }

    public function render()
    {
        $dr = Money::add('0', ...array_map(fn ($l) => is_numeric($l['debit']) ? $l['debit'] : '0', $this->lines));
        $cr = Money::add('0', ...array_map(fn ($l) => is_numeric($l['credit']) ? $l['credit'] : '0', $this->lines));

        return view('livewire.ops.finance.journals.editor', [
            'accounts' => Account::where('is_postable', true)->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all(),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->pluck('name', 'id')->all(),
            'branches' => Branch::orderBy('name')->pluck('name', 'id')->all(),
            'totalDebit' => $dr, 'totalCredit' => $cr, 'difference' => Money::sub($dr, $cr),
        ]);
    }
}
