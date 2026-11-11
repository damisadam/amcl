<?php

namespace App\Livewire\Ops\Admin;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Tenant roles and the granular permission matrix (LMS §1.2). */
#[Layout('layouts.ops')]
class Roles extends Component
{
    use HandlesActions;

    #[Url(except: null)]
    public ?int $roleId = null;

    public array $selected = [];

    public bool $showCreate = false;

    public string $newName = '';

    public string $newDescription = '';

    public ?int $copyFrom = null;

    public function mount(): void
    {
        $this->need('roles.view');
        $this->roleId ??= $this->roles()->first()?->id;
        $this->loadRole();
    }

    private function roles()
    {
        return Role::where('tenant_id', TenantContext::id())->withCount('users')->orderByDesc('is_system')->orderBy('name')->get();
    }

    private function role(): Role
    {
        return Role::where('tenant_id', TenantContext::id())->findOrFail($this->roleId);
    }

    public function select(int $id): void
    {
        $this->roleId = $id;
        $this->loadRole();
    }

    private function loadRole(): void
    {
        $this->selected = $this->roleId ? $this->role()->permissions()->pluck('key')->all() : [];
    }

    /** Permissions Tenant Admin always keeps (lock-out protection). Operational decisions are NOT included (segregation of duties). */
    public const ADMIN_ESSENTIAL = ['users.view', 'users.manage', 'roles.view', 'roles.manage', 'audit.view'];

    public function save(): void
    {
        $this->need('roles.manage');
        $role = $this->role();
        if ($role->key === 'tenant_admin') {
            // Administration permissions can never be removed from Tenant Admin, so the institution cannot lock itself out.
            $this->selected = array_values(array_unique(array_merge($this->selected, self::ADMIN_ESSENTIAL)));
        }
        $valid = Permission::where('scope', 'tenant')->whereIn('key', $this->selected)->pluck('id', 'key');
        $old = $role->permissions()->pluck('key')->sort()->values()->all();
        $new = $valid->keys()->sort()->values()->all();
        $role->permissions()->sync($valid->values()->all());
        Auditor::record('roles.permissions_changed', $role, ['permissions' => array_values(array_diff($old, $new))], ['permissions' => array_values(array_diff($new, $old))],
            'Added '.count(array_diff($new, $old)).', removed '.count(array_diff($old, $new)));
        $this->toast(__('Permissions saved. They take effect on the users\' next request.'));
    }

    public function create(): void
    {
        $this->need('roles.manage');
        $this->validate(['newName' => 'required|string|min:3|max:60', 'newDescription' => 'nullable|string|max:200', 'copyFrom' => 'nullable|integer']);
        $key = Str::slug($this->newName, '_');
        if (Role::where('tenant_id', TenantContext::id())->where('key', $key)->exists()) {
            $this->addError('newName', __('A role with this name already exists.'));

            return;
        }
        $role = Role::forceCreate(['tenant_id' => TenantContext::id(), 'key' => $key, 'name' => $this->newName, 'description' => $this->newDescription ?: null, 'scope' => 'tenant', 'is_system' => false]);
        if ($this->copyFrom) {
            $role->permissions()->sync(Role::where('tenant_id', TenantContext::id())->findOrFail($this->copyFrom)->permissions()->pluck('permissions.id'));
        }
        Auditor::record('roles.created', $role, [], ['key' => $key, 'copied_from' => $this->copyFrom]);
        $this->reset('newName', 'newDescription', 'copyFrom', 'showCreate');
        $this->select($role->id);
        $this->toast(__('Role created.'));
    }

    public function delete(): void
    {
        $this->need('roles.manage');
        $role = $this->role();
        if ($role->is_system || $role->key === 'tenant_admin') {
            $this->toast(__('System roles cannot be deleted.'), 'red');

            return;
        }
        if ($role->users()->exists() || \App\Models\ApprovalStage::where('role_id', $role->id)->exists()) {
            $this->toast(__('Remove this role from users and approval stages before deleting it.'), 'red');

            return;
        }
        Auditor::record('roles.deleted', $role, ['key' => $role->key, 'permissions' => $role->permissions()->pluck('key')->all()]);
        $role->delete();
        $this->roleId = $this->roles()->first()?->id;
        $this->loadRole();
        $this->toast(__('Role deleted.'));
    }

    public function render()
    {
        $this->need('roles.view');
        $catalog = [];
        foreach (config('permissions.tenant') as $module => $perms) {
            foreach ($perms as $key => $label) {
                $catalog[$module][] = ['key' => rtrim($key, '*'), 'label' => $label, 'sensitive' => str_ends_with($key, '*')];
            }
        }

        return view('livewire.ops.admin.roles', [
            'roles' => $this->roles(),
            'current' => $this->roleId ? $this->role() : null,
            'catalog' => $catalog,
            'canManage' => auth()->user()->hasPermission('roles.manage'),
            'stagesUsing' => $this->roleId ? \App\Models\ApprovalStage::with('workflow')->where('role_id', $this->roleId)->get() : collect(),
        ])->title(__('Roles & permissions'));
    }
}
