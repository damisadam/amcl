<?php

namespace App\Livewire\Ops\Admin;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\Product;
use App\Models\Region;
use App\Services\Audit\Auditor;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Tenant → Region → Area → Branch hierarchy, departments, employees, office products & office access to funds (LMS §9). */
#[Layout('layouts.ops')]
class Organization extends Component
{
    use HandlesActions, WithPagination;

    #[Url(except: 'hierarchy')]
    public string $tab = 'hierarchy';

    public bool $showNode = false;

    public string $nodeType = 'region';

    public ?int $nodeId = null;

    public array $node = [];

    public bool $showEmployee = false;

    public ?int $employeeId = null;

    public array $emp = [];

    public string $empSearch = '';

    public function mount(): void
    {
        $this->need('org.manage');
    }

    // ---------------------------------------------------------- hierarchy nodes

    public function newNode(string $type, ?int $parentId = null): void
    {
        $this->need('org.manage');
        abort_unless(in_array($type, ['region', 'area', 'branch', 'department'], true), 422);
        $this->nodeType = $type;
        $this->nodeId = null;
        $this->node = ['code' => '', 'name' => '', 'parent_id' => $parentId, 'status' => 'active', 'city' => '', 'address' => '', 'phone' => '', 'is_head_office' => false];
        $this->resetValidation();
        $this->showNode = true;
    }

    public function editNode(string $type, int $id): void
    {
        $this->need('org.manage');
        $m = $this->nodeModel($type)::findOrFail($id);
        $this->nodeType = $type;
        $this->nodeId = $m->id;
        $this->node = ['code' => $m->code, 'name' => $m->name, 'parent_id' => $type === 'area' ? $m->region_id : ($type === 'branch' ? $m->area_id : null),
            'status' => $m->status ?? 'active', 'city' => $m->city ?? '', 'address' => $m->address ?? '', 'phone' => $m->phone ?? '', 'is_head_office' => (bool) ($m->is_head_office ?? false)];
        $this->resetValidation();
        $this->showNode = true;
    }

    public function saveNode(): void
    {
        $this->need('org.manage');
        $cls = $this->nodeModel($this->nodeType);
        $table = (new $cls)->getTable();
        $this->validate([
            'node.code' => ['required', 'string', 'max:20', Rule::unique($table, 'code')->where('tenant_id', \App\Support\TenantContext::id())->ignore($this->nodeId)],
            'node.name' => 'required|string|max:120',
            'node.parent_id' => in_array($this->nodeType, ['area', 'branch'], true) ? 'required|integer' : 'nullable',
            'node.status' => 'nullable|in:active,inactive',
        ]);
        $attrs = ['code' => strtoupper($this->node['code']), 'name' => $this->node['name']];
        if ($this->nodeType !== 'department') {
            $attrs['status'] = $this->node['status'] ?: 'active';
        }
        if ($this->nodeType === 'area') {
            $attrs['region_id'] = Region::findOrFail($this->node['parent_id'])->id;
        }
        if ($this->nodeType === 'branch') {
            $attrs += ['area_id' => Area::findOrFail($this->node['parent_id'])->id, 'city' => $this->node['city'] ?: null, 'address' => $this->node['address'] ?: null,
                'phone' => $this->node['phone'] ?: null, 'is_head_office' => (bool) $this->node['is_head_office']];
            if ($attrs['is_head_office'] && Branch::where('is_head_office', true)->whereKeyNot($this->nodeId ?? 0)->exists()) {
                $this->addError('node.is_head_office', __('Only one branch can be the head office.'));

                return;
            }
        }
        $m = $this->nodeId ? $cls::findOrFail($this->nodeId) : new $cls;
        $m->fill($attrs);
        $m->exists ? Auditor::changes("org.{$this->nodeType}_updated", $m) : null;
        $isNew = ! $m->exists;
        $m->save();
        if ($isNew) {
            Auditor::record("org.{$this->nodeType}_created", $m, [], $attrs);
        }
        $this->showNode = false;
        $this->toast(__('Saved.'));
    }

    private function nodeModel(string $type): string
    {
        return ['region' => Region::class, 'area' => Area::class, 'branch' => Branch::class, 'department' => Department::class][$type] ?? abort(422);
    }

    // ---------------------------------------------------------- employees

    public function newEmployee(): void
    {
        $this->need('org.manage');
        $this->employeeId = null;
        $this->emp = ['employee_code' => '', 'name' => '', 'cnic' => '', 'designation' => '', 'branch_id' => '', 'department_id' => '', 'reports_to_id' => '',
            'phone' => '', 'email' => '', 'joined_on' => '', 'basic_salary' => '0', 'allowances' => '0', 'bank_iban' => '', 'status' => 'active'];
        $this->resetValidation();
        $this->showEmployee = true;
    }

    public function editEmployee(int $id): void
    {
        $this->need('org.manage');
        $e = Employee::findOrFail($id);
        $this->employeeId = $e->id;
        $this->emp = collect($e->only(['employee_code', 'name', 'cnic', 'designation', 'branch_id', 'department_id', 'reports_to_id', 'phone', 'email', 'basic_salary', 'allowances', 'bank_iban', 'status']))
            ->map(fn ($v) => (string) $v)->all() + ['joined_on' => $e->joined_on?->toDateString() ?? ''];
        $this->resetValidation();
        $this->showEmployee = true;
    }

    public function saveEmployee(): void
    {
        $this->need('org.manage');
        $this->validate([
            'emp.employee_code' => ['required', 'string', 'max:30', Rule::unique('employees', 'employee_code')->where('tenant_id', \App\Support\TenantContext::id())->ignore($this->employeeId)],
            'emp.name' => 'required|string|max:120', 'emp.cnic' => ['nullable', 'regex:/^\d{5}-\d{7}-\d$/'], 'emp.designation' => 'nullable|string|max:100',
            'emp.email' => 'nullable|email', 'emp.phone' => 'nullable|string|max:20', 'emp.joined_on' => 'nullable|date',
            'emp.basic_salary' => 'required|numeric|min:0', 'emp.allowances' => 'required|numeric|min:0', 'emp.bank_iban' => 'nullable|string|max:34',
            'emp.status' => 'required|in:active,inactive,terminated',
        ]);
        $d = collect($this->emp)->map(fn ($v) => $v === '' ? null : $v)->all();
        foreach (['branch_id' => Branch::class, 'department_id' => Department::class, 'reports_to_id' => Employee::class] as $k => $cls) {
            if ($d[$k] !== null && ! $cls::whereKey($d[$k])->exists()) {
                $this->addError('emp.'.$k, __('Invalid selection.'));

                return;
            }
        }
        if ($this->employeeId && (int) $d['reports_to_id'] === $this->employeeId) {
            $this->addError('emp.reports_to_id', __('An employee cannot report to themselves.'));

            return;
        }
        $e = $this->employeeId ? Employee::findOrFail($this->employeeId) : new Employee;
        $e->fill($d);
        if ($e->exists) {
            Auditor::changes('org.employee_updated', $e);
            $e->save();
        } else {
            $e->save();
            Auditor::record('org.employee_created', $e, [], ['code' => $e->employee_code, 'name' => $e->name]);
        }
        $this->showEmployee = false;
        $this->toast(__('Employee saved.'));
    }

    // ---------------------------------------------------------- matrices

    public function toggleProduct(int $branchId, int $productId): void
    {
        $this->need('org.manage');
        $b = Branch::findOrFail($branchId);
        $p = Product::findOrFail($productId);
        $res = $b->products()->toggle($p->id);
        Auditor::record('org.branch_product_toggled', $b, [], ['product' => $p->code, 'enabled' => ! empty($res['attached'])]);
    }

    public function toggleFund(int $branchId, int $fundId): void
    {
        $this->need('org.manage');
        $b = Branch::findOrFail($branchId);
        $f = Fund::findOrFail($fundId);
        $res = $b->funds()->toggle($f->id);
        Auditor::record('org.branch_fund_toggled', $b, [], ['fund' => $f->code, 'enabled' => ! empty($res['attached'])]);
    }

    public function render()
    {
        $this->need('org.manage');

        return view('livewire.ops.admin.organization', [
            'regions' => Region::with(['areas.branches' => fn ($q) => $q->withCount('products')])->orderBy('code')->get(),
            'regionOptions' => Region::orderBy('name')->pluck('name', 'id'),
            'areaOptions' => Area::with('region')->orderBy('name')->get()->mapWithKeys(fn ($a) => [$a->id => $a->region->name.' › '.$a->name]),
            'departments' => Department::orderBy('name')->get(),
            'employees' => $this->tab === 'employees' ? Employee::with(['branch', 'department', 'reportsTo'])
                ->when($this->empSearch, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->empSearch}%")->orWhere('employee_code', 'like', "%{$this->empSearch}%")))
                ->orderBy('employee_code')->paginate(15) : null,
            'branches' => Branch::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(),
            'funds' => Fund::orderBy('name')->get(),
            'branchProducts' => \DB::table('branch_product')->whereIn('branch_id', Branch::pluck('id'))->get()->map(fn ($r) => $r->branch_id.'-'.$r->product_id)->flip(),
            'branchFunds' => \DB::table('branch_fund')->whereIn('branch_id', Branch::pluck('id'))->get()->map(fn ($r) => $r->branch_id.'-'.$r->fund_id)->flip(),
            'branchOptions' => Branch::orderBy('name')->pluck('name', 'id'),
            'departmentOptions' => Department::orderBy('name')->pluck('name', 'id'),
            'employeeOptions' => Employee::orderBy('name')->pluck('name', 'id'),
        ])->title(__('Organization'));
    }
}
