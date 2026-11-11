<?php

namespace App\Livewire\Ops\Admin;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Read-only, tenant-scoped audit trail with filters, value diff and audited CSV export. */
#[Layout('layouts.ops')]
class Audit extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')]
    public string $filterActor = '';

    #[Url(except: '')]
    public string $filterAction = '';

    #[Url(except: '')]
    public string $filterSubject = '';

    #[Url(except: '')]
    public string $filterFrom = '';

    #[Url(except: '')]
    public string $filterTo = '';

    #[Url(except: '')]
    public string $filterCorrelation = '';

    public ?int $detailId = null;

    public bool $showDetail = false;

    public function mount(): void
    {
        $this->need('audit.view');
    }

    /** Shared filter definition used by the screen and the CSV export. */
    public static function filtered(array $f): Builder
    {
        return AuditLog::query()->where('tenant_id', TenantContext::id() ?? 0)
            ->when($f['actor'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($f['subject'] ?? null, fn ($q, $v) => $q->where('auditable_type', 'like', '%'.$v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($f['correlation'] ?? null, fn ($q, $v) => $q->where('correlation_id', $v));
    }

    private function filters(): array
    {
        return ['actor' => $this->filterActor, 'action' => $this->filterAction, 'subject' => $this->filterSubject, 'from' => $this->filterFrom, 'to' => $this->filterTo, 'correlation' => $this->filterCorrelation];
    }

    public function view(int $id): void
    {
        $this->need('audit.view');
        $this->detailId = self::filtered([])->findOrFail($id)->id;
        $this->showDetail = true;
    }

    public function render()
    {
        $this->need('audit.view');
        $q = self::filtered($this->filters())->with('actor');
        $this->applySearch($q, ['action', 'reason']);

        return view('livewire.ops.admin.audit', [
            'logs' => $q->latest('id')->paginate(25),
            'actors' => User::withTrashed()->whereIn('type', ['staff', 'vendor', 'customer'])->whereIn('id', AuditLog::where('tenant_id', TenantContext::id())->select('actor_id')->distinct())
                ->orderBy('name')->pluck('name', 'id'),
            'subjects' => AuditLog::where('tenant_id', TenantContext::id())->whereNotNull('auditable_type')->distinct()->pluck('auditable_type')
                ->mapWithKeys(fn ($t) => [class_basename($t) => class_basename($t)])->sort(),
            'detail' => $this->detailId ? AuditLog::with('actor')->find($this->detailId) : null,
            'canExport' => auth()->user()->hasPermission('audit.export'),
            'exportUrl' => route('ops.admin.audit.export', array_filter($this->filters())),
        ])->title(__('Audit log'));
    }
}
