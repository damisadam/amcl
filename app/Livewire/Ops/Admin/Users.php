<?php

namespace App\Livewire\Ops\Admin;

use App\Enums\UserStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Identity\AuthService;
use App\Services\Identity\UserAdminService;
use App\Support\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Staff and vendor-portal users of this institution. */
#[Layout('layouts.ops')]
class Users extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')]
    public string $filterRole = '';

    #[Url(except: '')]
    public string $filterBranch = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterType = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public array $roleIds = [];

    public bool $showStatus = false;

    public ?int $statusUserId = null;

    public string $targetStatus = '';

    public string $statusReason = '';

    public ?string $tempPassword = null;

    public ?string $tempFor = null;

    public ?int $activityId = null;

    public bool $showActivity = false;

    public function mount(): void
    {
        $this->need('users.view');
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->form = ['type' => 'staff', 'name' => '', 'email' => '', 'mobile' => '', 'designation' => '', 'branch_id' => '', 'department_id' => '', 'employee_id' => '', 'vendor_id' => ''];
        $this->roleIds = [];
        $this->editingId = null;
    }

    public function create(): void
    {
        $this->need('users.manage');
        $this->resetForm();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->need('users.manage');
        $u = User::whereIn('type', ['staff', 'vendor'])->findOrFail($id);
        $this->editingId = $u->id;
        $this->form = ['type' => $u->type->value, 'name' => $u->name, 'email' => (string) $u->email, 'mobile' => (string) $u->mobile, 'designation' => (string) $u->designation,
            'branch_id' => (string) $u->branch_id, 'department_id' => (string) $u->department_id, 'employee_id' => (string) $u->employee_id, 'vendor_id' => (string) $u->vendor_id];
        $this->roleIds = $u->roles()->pluck('roles.id')->map(fn ($i) => (string) $i)->all();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(UserAdminService $svc): void
    {
        $this->need('users.manage');
        $this->validate([
            'form.type' => 'required|in:staff,vendor',
            'form.name' => 'required|string|min:3|max:100',
            'form.email' => 'required|email|max:150',
            'form.mobile' => ['nullable', 'regex:/^03\d{9}$/'],
            'form.designation' => 'nullable|string|max:100',
            'form.branch_id' => 'nullable|integer',
            'form.department_id' => 'nullable|integer',
            'form.employee_id' => 'nullable|integer',
            'form.vendor_id' => 'required_if:form.type,vendor|nullable|integer',
            'roleIds' => $this->form['type'] === 'staff' ? 'required|array|min:1' : 'array',
        ], ['roleIds.required' => __('Assign at least one role.')]);

        // Scope every referenced id to this tenant (ids from the browser are untrusted).
        foreach (['branch_id' => Branch::class, 'department_id' => Department::class, 'employee_id' => Employee::class, 'vendor_id' => Vendor::class] as $k => $cls) {
            if ($this->form[$k] !== '' && $this->form[$k] !== null && ! $cls::whereKey($this->form[$k])->exists()) {
                $this->addError('form.'.$k, __('Invalid selection.'));

                return;
            }
        }
        $data = collect($this->form)->map(fn ($v) => $v === '' ? null : $v)->all();
        $data['email'] = strtolower($data['email']);

        if ($this->editingId) {
            $user = User::findOrFail($this->editingId);
            $ok = $this->attempt(fn () => $svc->update($user, $data, $this->roleIds, auth()->user()), __('User updated.'));
        } else {
            $ok = $this->attempt(function () use ($svc, $data) {
                [$user, $temp] = $svc->create($data, $this->roleIds, auth()->user());
                $this->tempPassword = $temp;
                $this->tempFor = $user->email;

                return $user;
            }, __('User created.'));
        }
        if ($ok) {
            $this->showForm = false;
            $this->resetForm();
        }
    }

    public function openStatus(int $id, string $to): void
    {
        $this->need('users.manage');
        $this->statusUserId = User::findOrFail($id)->id;
        $this->targetStatus = UserStatus::from($to)->value;
        $this->statusReason = '';
        $this->showStatus = true;
    }

    public function changeStatus(UserAdminService $svc): void
    {
        $this->need('users.manage');
        $this->validate(['statusReason' => 'required|string|min:5|max:300']);
        $u = User::findOrFail($this->statusUserId);
        if ($this->attempt(fn () => $svc->changeStatus($u, UserStatus::from($this->targetStatus), $this->statusReason, auth()->user()), __('Status updated.'))) {
            $this->showStatus = false;
        }
    }

    public function unlock(int $id, UserAdminService $svc): void
    {
        $this->need('users.reset_password');
        $this->attempt(fn () => $svc->unlock(User::findOrFail($id), auth()->user()), __('Account unlocked.'));
    }

    public function resetPassword(int $id, AuthService $auth): void
    {
        $this->need('users.reset_password');
        $u = User::whereIn('type', ['staff', 'vendor'])->findOrFail($id);
        if ((int) $u->id === (int) auth()->id()) {
            $this->toast(__('Use Change password for your own account.'), 'red');

            return;
        }
        $this->attempt(function () use ($auth, $u) {
            $this->tempPassword = $auth->adminReset($u, auth()->user());
            $this->tempFor = $u->email;
        }, __('Password reset. The user must change it at next sign-in.'));
    }

    public function dismissTemp(): void
    {
        $this->tempPassword = $this->tempFor = null;
    }

    public function activity(int $id): void
    {
        $this->need('users.view');
        $this->activityId = User::findOrFail($id)->id;
        $this->showActivity = true;
    }

    public function render()
    {
        $this->need('users.view');
        $q = User::query()->withTrashed()->whereIn('type', ['staff', 'vendor'])->with(['roles', 'branch', 'vendor'])
            ->when($this->filterRole, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('roles.id', $this->filterRole)))
            ->when($this->filterBranch, fn ($q) => $q->where('branch_id', $this->filterBranch))
            ->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus), fn ($q) => $q->whereNull('deleted_at'));
        $this->applySearch($q, ['name', 'email', 'mobile', 'designation']);
        $activityUser = $this->activityId ? User::withTrashed()->with('devices')->find($this->activityId) : null;

        return view('livewire.ops.admin.users', [
            'users' => $this->applySort($q, 'name', ['name', 'last_login_at', 'created_at'])->paginate($this->perPage),
            'roles' => Role::where('tenant_id', TenantContext::id())->orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'departments' => Department::orderBy('name')->pluck('name', 'id'),
            'employees' => Employee::where('status', 'active')->orderBy('name')->get()->mapWithKeys(fn ($e) => [$e->id => $e->employee_code.' · '.$e->name]),
            'vendors' => Vendor::orderBy('business_name')->pluck('business_name', 'id'),
            'statuses' => UserStatus::options(),
            'activityUser' => $activityUser,
            'activityLog' => $activityUser ? \App\Models\AuditLog::where('tenant_id', TenantContext::id())->where('actor_id', $activityUser->id)->latest('id')->take(15)->get() : collect(),
            'canManage' => auth()->user()->hasPermission('users.manage'),
            'canReset' => auth()->user()->hasPermission('users.reset_password'),
        ])->title(__('Users'));
    }
}
