<?php

namespace App\Livewire\Ops\StaffLending;

use App\Livewire\Concerns\HandlesActions;
use App\Models\CorporateClient;
use App\Models\CorporateStaff;
use App\Models\StaffSalary;
use App\Services\Lending\StaffLendingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions;

    public CorporateClient $corporate;

    #[Url]
    public string $tab = 'staff';

    public array $info = [];

    public array $staffForm = ['id' => null, 'employee_no' => '', 'name' => '', 'cnic' => '', 'designation' => '', 'joined_on' => '', 'salary_band' => '', 'monthly_salary' => ''];

    public string $linkStaffId = '';

    public string $linkRef = '';

    public string $period = '';

    public array $salary = ['staff_id' => '', 'gross' => '', 'deduction' => ''];

    public string $csv = '';

    public array $importErrors = [];

    public function mount(CorporateClient $corporate): void
    {
        $this->need('staff_lending.manage');
        $this->corporate = $corporate;
        $this->info = $corporate->only(['company_name', 'registration_no', 'ntn', 'address', 'contact_person', 'contact_phone', 'salary_day', 'status']);
        $this->period = now()->format('Y-m');
    }

    public function saveInfo(StaffLendingService $svc): void
    {
        $this->need('staff_lending.manage');
        $this->validate(['info.company_name' => 'required|string|max:150', 'info.salary_day' => 'required|integer|min:1|max:28', 'info.status' => 'required|in:active,inactive']);
        $this->attempt(fn () => $svc->saveCorporate($this->info, auth()->user(), $this->corporate), __('Saved.'));
    }

    public function editStaff(int $id): void
    {
        $s = $this->corporate->staff()->findOrFail($id);
        $this->staffForm = ['id' => $s->id] + array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v,
            $s->only(['employee_no', 'name', 'cnic', 'designation', 'joined_on', 'salary_band', 'monthly_salary']));
    }

    public function saveStaff(StaffLendingService $svc): void
    {
        $this->need('staff_lending.manage');
        $this->validate(['staffForm.employee_no' => 'required|string|max:30', 'staffForm.name' => 'required|string|max:120', 'staffForm.cnic' => ['nullable', 'regex:/^\d{5}-\d{7}-\d$/'],
            'staffForm.joined_on' => 'nullable|date', 'staffForm.monthly_salary' => 'required|numeric|min:0', 'staffForm.designation' => 'nullable|string|max:80', 'staffForm.salary_band' => 'nullable|string|max:10']);
        $data = collect($this->staffForm)->except('id')->map(fn ($v) => $v === '' ? null : $v)->all();
        $existing = $this->staffForm['id'] ? $this->corporate->staff()->find($this->staffForm['id']) : null;
        if ($this->attempt(fn () => $svc->saveStaff($this->corporate, $data, auth()->user(), $existing), __('Staff record saved.'))) {
            $this->staffForm = ['id' => null, 'employee_no' => '', 'name' => '', 'cnic' => '', 'designation' => '', 'joined_on' => '', 'salary_band' => '', 'monthly_salary' => ''];
        }
    }

    public function link(StaffLendingService $svc): void
    {
        $this->need('staff_lending.manage');
        $this->validate(['linkStaffId' => 'required', 'linkRef' => 'required|string']);
        $s = $this->corporate->staff()->findOrFail($this->linkStaffId);
        if ($this->attempt(fn () => $svc->linkCustomer($s, trim($this->linkRef), auth()->user()), __('Staff linked to customer CIF.'))) {
            $this->reset('linkStaffId', 'linkRef');
        }
    }

    public function saveSalary(StaffLendingService $svc): void
    {
        $this->need('staff_lending.manage');
        $this->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'salary.staff_id' => 'required', 'salary.gross' => 'required|numeric|min:1', 'salary.deduction' => 'nullable|numeric|min:0']);
        $s = $this->corporate->staff()->findOrFail($this->salary['staff_id']);
        if ($this->attempt(fn () => $svc->recordSalary($s, $this->period, (string) $this->salary['gross'], $this->salary['deduction'] === '' ? null : (string) $this->salary['deduction']), __('Salary recorded.'))) {
            $this->salary = ['staff_id' => '', 'gross' => '', 'deduction' => ''];
        }
    }

    public function import(StaffLendingService $svc): void
    {
        $this->need('staff_lending.manage');
        $this->validate(['csv' => 'required|string|max:200000']);
        [$ok, $errors] = $svc->importCsv($this->corporate, $this->csv);
        $this->importErrors = $errors;
        $this->toast(__(':n salary rows imported.', ['n' => $ok]).($errors ? ' '.__(':e errors.', ['e' => count($errors)]) : ''), $errors ? 'red' : 'green');
        if (! $errors) {
            $this->csv = '';
        }
    }

    public function postDeduction(int $staffId, StaffLendingService $svc): void
    {
        $this->need('staff_lending.manage');
        $this->need('repayments.record');
        $s = $this->corporate->staff()->findOrFail($staffId);
        $this->attempt(fn () => $svc->postDeduction($s, $this->period, auth()->user()), __('Salary deduction posted as repayment.'));
    }

    public function render(StaffLendingService $svc)
    {
        $data = ['staff' => $this->corporate->staff()->with('customer')->orderBy('name')->get()];
        if ($this->tab === 'salaries') {
            $data['salaries'] = StaffSalary::with('staff')->whereIn('corporate_staff_id', $data['staff']->pluck('id'))->where('period', $this->period)->get();
        }
        if ($this->tab === 'reconciliation' && preg_match('/^\d{4}-\d{2}$/', $this->period)) {
            $data['recon'] = $svc->reconciliation($this->corporate, $this->period);
        }

        return view('livewire.ops.staff-lending.show', $data);
    }
}
