<?php

namespace App\Livewire\Ops\StaffLending;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\CorporateClient;
use App\Services\Lending\StaffLendingService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Corporate clients running staff (payroll-deducted) financing programmes (LMS §5). */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    public bool $showCreate = false;

    public array $form = ['company_name' => '', 'registration_no' => '', 'ntn' => '', 'address' => '', 'contact_person' => '', 'contact_phone' => '', 'salary_day' => '1'];

    public function mount(): void
    {
        $this->need('staff_lending.manage');
    }

    public function save(StaffLendingService $svc)
    {
        $this->need('staff_lending.manage');
        $this->validate(['form.company_name' => 'required|string|max:150', 'form.registration_no' => 'nullable|string|max:60', 'form.ntn' => 'nullable|string|max:20',
            'form.address' => 'nullable|string|max:255', 'form.contact_person' => 'nullable|string|max:100', 'form.contact_phone' => 'nullable|string|max:20',
            'form.salary_day' => 'required|integer|min:1|max:28']);
        $c = $this->attempt(fn () => $svc->saveCorporate($this->form, auth()->user()), __('Corporate client created.'));
        if ($c instanceof CorporateClient) {
            return $this->redirect(route('ops.staff-lending.show', $c), navigate: true);
        }
    }

    public function render()
    {
        $q = CorporateClient::query()->withCount('staff');
        $this->applySearch($q, ['company_name', 'code', 'ntn']);

        return view('livewire.ops.staff-lending.index', ['clients' => $q->orderBy('company_name')->paginate($this->perPage)]);
    }
}
