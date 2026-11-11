<?php

namespace App\Livewire\Ops\Admin;

use App\Livewire\Concerns\HandlesActions;
use App\Models\BlacklistEntry;
use App\Models\DocumentType;
use App\Models\Holiday;
use App\Models\LoanCategory;
use App\Models\PaymentType;
use App\Models\Tenant;
use App\Services\Audit\Auditor;
use App\Services\Tenancy\TenantSettings;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Company configuration (LMS §9, FMS §15) — all per tenant, all audited. */
#[Layout('layouts.ops')]
class Configuration extends Component
{
    use HandlesActions, WithPagination;

    #[Url(except: 'settings')]
    public string $tab = 'settings';

    public string $group = 'lending';

    public array $values = [];

    public array $holiday = ['date' => '', 'name' => ''];

    public array $category = ['code' => '', 'name' => '', 'regulatory_category' => ''];

    public array $blacklist = ['cnic' => '', 'name' => '', 'reason' => 'prior_default', 'notes' => ''];

    public string $blacklistSearch = '';

    public ?int $docTypeId = null;

    public array $docType = [];

    public bool $showDocType = false;

    public array $brand = [];

    public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function mount(): void
    {
        $this->need('config.manage', 'config.blacklist');
        if (! auth()->user()->hasPermission('config.manage')) {
            $this->tab = 'blacklist';
        }
        $this->loadGroup();
        $this->loadBrand();
    }

    private function tabPermission(): string
    {
        return $this->tab === 'blacklist' ? 'config.blacklist' : 'config.manage';
    }

    public function updatedTab(): void
    {
        $this->need($this->tabPermission());
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        abort_unless(array_key_exists($this->group, TenantSettings::DEFAULTS), 422);
        $this->loadGroup();
    }

    private function loadGroup(): void
    {
        $vals = TenantSettings::group($this->group);
        foreach ($vals as $k => $v) {
            if (is_array($v) && array_is_list($v)) {
                $vals[$k] = $k === 'days' ? array_map('strval', $v) : implode(', ', $v);
            } elseif (is_bool($v)) {
                $vals[$k] = $v;
            }
        }
        $this->values = $vals;
    }

    public function saveGroup(): void
    {
        $this->need('config.manage');
        $defaults = TenantSettings::DEFAULTS[$this->group] ?? abort(422);
        $clean = [];
        foreach ($defaults as $key => $default) {
            $v = $this->values[$key] ?? $default;
            if (is_bool($default)) {
                $clean[$key] = (bool) $v;
            } elseif (is_int($default)) {
                if (! is_numeric($v) || (int) $v < 0) {
                    $this->addError("values.$key", __('Enter a whole number ≥ 0.'));

                    return;
                }
                $clean[$key] = (int) $v;
            } elseif (is_float($default) || (is_string($default) && is_numeric($default))) {
                if (! is_numeric($v) || (float) $v < 0) {
                    $this->addError("values.$key", __('Enter a valid amount.'));

                    return;
                }
                $clean[$key] = is_float($default) ? (float) $v : number_format((float) $v, 2, '.', '');
            } elseif ($key === 'days') {
                $days = array_values(array_unique(array_map('intval', (array) $v)));
                if (! $days || array_diff($days, range(1, 7))) {
                    $this->addError("values.$key", __('Select at least one working day.'));

                    return;
                }
                sort($days);
                $clean[$key] = $days;
            } elseif (is_array($default) && array_is_list($default)) {
                $clean[$key] = array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen'));
                $clean[$key] = array_map(fn ($x) => is_numeric($x) ? (int) $x : $x, $clean[$key]);
            } elseif (is_array($default)) {
                foreach ($default as $sub => $_) {
                    if (! is_numeric($v[$sub] ?? null)) {
                        $this->addError("values.$key.$sub", __('Required number.'));

                        return;
                    }
                    $clean[$key][$sub] = (float) $v[$sub] == (int) $v[$sub] ? (int) $v[$sub] : (float) $v[$sub];
                }
            } elseif (preg_match('/^\d{2}:\d{2}$/', (string) $default)) {
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v)) {
                    $this->addError("values.$key", __('Use HH:MM.'));

                    return;
                }
                $clean[$key] = $v;
            } else {
                $clean[$key] = (string) $v;
            }
        }
        if ($this->group === 'wallet' && $clean['allow_cross_tenant_transfers']) {
            $this->addError('values.allow_cross_tenant_transfers', __('Cross-institution wallet transfers are not permitted in this release (DECISIONS D-003).'));

            return;
        }
        foreach ($clean as $k => $v) {
            TenantSettings::set($this->group, $k, $v);
        }
        $this->loadGroup();
        $this->toast(__('Settings saved.'));
    }

    // ------------------------------------------------- holidays / payment types / categories

    public function addHoliday(): void
    {
        $this->need('config.manage');
        $this->validate(['holiday.date' => ['required', 'date', Rule::unique('holidays', 'date')->where('tenant_id', TenantContext::id())], 'holiday.name' => 'required|string|max:100']);
        $h = Holiday::create($this->holiday);
        Auditor::record('config.holiday_added', $h, [], $this->holiday);
        $this->holiday = ['date' => '', 'name' => ''];
        $this->toast(__('Holiday added. New schedules will skip this date.'));
    }

    public function removeHoliday(int $id): void
    {
        $this->need('config.manage');
        $h = Holiday::findOrFail($id);
        Auditor::record('config.holiday_removed', $h, ['date' => $h->date->toDateString(), 'name' => $h->name]);
        $h->delete();
    }

    public function togglePaymentType(int $id): void
    {
        $this->need('config.manage');
        $p = PaymentType::findOrFail($id);
        $p->is_active = ! $p->is_active;
        Auditor::changes('config.payment_type_changed', $p);
        $p->save();
    }

    public function addCategory(): void
    {
        $this->need('config.manage');
        $this->validate(['category.code' => ['required', 'max:30', Rule::unique('loan_categories', 'code')->where('tenant_id', TenantContext::id())],
            'category.name' => 'required|max:100', 'category.regulatory_category' => 'nullable|max:100']);
        $c = LoanCategory::create(['code' => strtoupper($this->category['code'])] + $this->category);
        Auditor::record('config.loan_category_added', $c, [], $this->category);
        $this->category = ['code' => '', 'name' => '', 'regulatory_category' => ''];
        $this->toast(__('Category added.'));
    }

    // ------------------------------------------------- blacklist

    public function addBlacklist(): void
    {
        $this->need('config.blacklist');
        $this->validate(['blacklist.cnic' => ['nullable', 'regex:/^\d{5}-\d{7}-\d$/'], 'blacklist.name' => 'required|string|max:120',
            'blacklist.reason' => 'required|in:prior_default,fraud,aml,other', 'blacklist.notes' => 'required|string|min:5|max:1000']);
        $e = BlacklistEntry::create($this->blacklist + ['added_by' => auth()->id(), 'is_active' => true]);
        if ($e->cnic) {
            \App\Models\Customer::where('cnic', $e->cnic)->get()->each(fn ($c) => $c->forceFill(['is_blacklisted' => true, 'blacklist_reason' => $e->reason])->save());
        }
        Auditor::record('config.blacklist_added', $e, [], ['name' => $e->name, 'cnic' => $e->cnic, 'reason' => $e->reason], $e->notes);
        $this->blacklist = ['cnic' => '', 'name' => '', 'reason' => 'prior_default', 'notes' => ''];
        $this->toast(__('Added to blacklist. Matching customers are flagged and blocked from new financing.'));
    }

    public function deactivateBlacklist(int $id, string $reason = ''): void
    {
        $this->need('config.blacklist');
        $e = BlacklistEntry::findOrFail($id);
        $e->update(['is_active' => false]);
        if ($e->cnic && ! BlacklistEntry::where('cnic', $e->cnic)->where('is_active', true)->exists()) {
            \App\Models\Customer::where('cnic', $e->cnic)->get()->each(fn ($c) => $c->forceFill(['is_blacklisted' => false, 'blacklist_reason' => null])->save());
        }
        Auditor::record('config.blacklist_removed', $e, ['is_active' => true], ['is_active' => false], $reason ?: 'Removed by '.auth()->user()->name);
        $this->toast(__('Blacklist entry deactivated.'));
    }

    // ------------------------------------------------- document types

    public function editDocType(?int $id = null): void
    {
        $this->need('config.manage');
        $d = $id ? DocumentType::findOrFail($id) : null;
        $this->docTypeId = $d?->id;
        $this->docType = ['code' => $d?->code ?? '', 'name' => $d?->name ?? '', 'name_ur' => $d?->name_ur ?? '', 'category' => $d?->category ?? 'other',
            'validity_days' => (string) ($d?->validity_days ?? ''), 'tracks_expiry' => (bool) ($d?->tracks_expiry ?? false), 'signing_method' => $d?->signing_method ?? 'none',
            'verification_step' => $d?->verification_step ?? '', 'is_active' => (bool) ($d?->is_active ?? true)];
        $this->resetValidation();
        $this->showDocType = true;
    }

    public function saveDocType(): void
    {
        $this->need('config.manage');
        $this->validate([
            'docType.code' => ['required', 'max:40', 'regex:/^[A-Z0-9_]+$/', Rule::unique('document_types', 'code')->where('tenant_id', TenantContext::id())->ignore($this->docTypeId)],
            'docType.name' => 'required|max:120', 'docType.name_ur' => 'nullable|max:120',
            'docType.category' => 'required|in:identity,income,business,guarantor,loan,agreement,vendor,field,other',
            'docType.validity_days' => 'nullable|integer|min:1|max:3650', 'docType.signing_method' => 'required|in:none,digital,conditional,wet_ink',
            'docType.verification_step' => 'nullable|max:40',
        ]);
        $data = $this->docType;
        $data['validity_days'] = $data['validity_days'] === '' ? null : (int) $data['validity_days'];
        $data['verification_step'] = $data['verification_step'] ?: null;
        $d = $this->docTypeId ? DocumentType::findOrFail($this->docTypeId) : new DocumentType;
        $d->fill($data);
        $d->exists ? Auditor::changes('config.document_type_updated', $d) : null;
        $new = ! $d->exists;
        $d->save();
        if ($new) {
            Auditor::record('config.document_type_created', $d, [], $data);
        }
        $this->showDocType = false;
        $this->toast(__('Document type saved.'));
    }

    // ------------------------------------------------- branding

    private function loadBrand(): void
    {
        $t = TenantContext::require();
        $this->brand = collect($t->only(['name', 'legal_name', 'tagline', 'tagline_ur', 'primary_color', 'support_phone', 'support_email', 'address', 'city', 'default_locale']))
            ->map(fn ($v) => (string) $v)->all();
    }

    public function saveBrand(): void
    {
        $this->need('config.manage');
        $this->validate(['brand.name' => 'required|max:120', 'brand.legal_name' => 'nullable|max:200', 'brand.tagline' => 'nullable|max:200', 'brand.tagline_ur' => 'nullable|max:200',
            'brand.primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'brand.support_phone' => 'nullable|max:30', 'brand.support_email' => 'nullable|email',
            'brand.address' => 'nullable|max:255', 'brand.city' => 'nullable|max:80', 'brand.default_locale' => 'required|in:en,ur']);
        $t = Tenant::findOrFail(TenantContext::id());
        $t->fill(collect($this->brand)->map(fn ($v) => $v === '' ? null : $v)->all());
        Auditor::changes('config.branding_updated', $t);
        $t->save();
        TenantContext::set($t);
        $this->toast(__('Branding saved.'));
    }

    public function render()
    {
        $this->need($this->tabPermission());

        return view('livewire.ops.admin.configuration', [
            'groups' => array_keys(TenantSettings::DEFAULTS),
            'defaults' => TenantSettings::DEFAULTS[$this->group] ?? [],
            'holidays' => $this->tab === 'holidays' ? Holiday::orderBy('date')->get() : collect(),
            'paymentTypes' => $this->tab === 'payments' ? PaymentType::orderBy('name')->get() : collect(),
            'categories' => $this->tab === 'categories' ? LoanCategory::orderBy('code')->get() : collect(),
            'blacklistEntries' => $this->tab === 'blacklist' ? BlacklistEntry::with('addedBy')
                ->when($this->blacklistSearch, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->blacklistSearch}%")->orWhere('cnic', 'like', "%{$this->blacklistSearch}%")))
                ->latest('id')->paginate(15) : null,
            'docTypes' => $this->tab === 'documents' ? DocumentType::orderBy('category')->orderBy('name')->get() : collect(),
            'tenant' => TenantContext::require(),
            'canConfig' => auth()->user()->hasPermission('config.manage'),
            'canBlacklist' => auth()->user()->hasPermission('config.blacklist'),
        ])->title(__('Company configuration'));
    }
}
