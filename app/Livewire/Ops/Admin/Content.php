<?php

namespace App\Livewire\Ops\Admin;

use App\Livewire\Concerns\HandlesActions;
use App\Models\ContentItem;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** No-code content (Agreement §8.4): FAQs, banners, notices, notification templates and delivery log. */
#[Layout('layouts.ops')]
class Content extends Component
{
    use HandlesActions, WithPagination;

    #[Url(except: 'content')]
    public string $tab = 'content';

    public bool $showItem = false;

    public ?int $itemId = null;

    public array $item = [];

    public bool $showTemplate = false;

    public ?int $templateId = null;

    public array $tpl = [];

    public string $logChannel = '';

    public string $logStatus = '';

    public const EVENTS = ['application_status', 'application_returned', 'instalment_reminder', 'payment_received', 'payment_failed', 'disbursement', 'noc_issued',
        'loan_closed', 'wallet_topup', 'wallet_debit', 'wallet_received', 'wallet_sent', 'wallet_failed', 'wallet_reversal', 'wallet_refund', 'kyc_passed', 'kyc_failed',
        'kyc_pending_review', 'complaint_opened', 'complaint_reply', 'complaint_status', 'payment_delay_notice', 'verification_visit'];

    public function mount(): void
    {
        $this->need('content.manage');
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function editItem(?int $id = null): void
    {
        $this->need('content.manage');
        $c = $id ? ContentItem::findOrFail($id) : null;
        $this->itemId = $c?->id;
        $this->item = ['type' => $c?->type ?? 'faq', 'title' => $c?->title ?? '', 'title_ur' => $c?->title_ur ?? '', 'body' => $c?->body ?? '', 'body_ur' => $c?->body_ur ?? '',
            'sort' => (string) ($c?->sort ?? 0), 'is_active' => (bool) ($c?->is_active ?? true)];
        $this->resetValidation();
        $this->showItem = true;
    }

    public function saveItem(): void
    {
        $this->need('content.manage');
        $this->validate(['item.type' => 'required|in:faq,banner,notice,help', 'item.title' => 'required|max:200', 'item.title_ur' => 'nullable|max:200',
            'item.body' => 'nullable|max:5000', 'item.body_ur' => 'nullable|max:5000', 'item.sort' => 'required|integer|min:0|max:999']);
        $c = $this->itemId ? ContentItem::findOrFail($this->itemId) : new ContentItem;
        $c->fill($this->item);
        $new = ! $c->exists;
        $new ? null : Auditor::changes('content.item_updated', $c);
        $c->save();
        if ($new) {
            Auditor::record('content.item_created', $c, [], ['type' => $c->type, 'title' => $c->title]);
        }
        $this->showItem = false;
        $this->toast(__('Saved. Changes appear on the customer portal immediately.'));
    }

    public function deleteItem(int $id): void
    {
        $this->need('content.manage');
        $c = ContentItem::findOrFail($id);
        Auditor::record('content.item_deleted', $c, ['type' => $c->type, 'title' => $c->title]);
        $c->delete();
    }

    public function editTemplate(?int $id = null): void
    {
        $this->need('content.manage');
        $t = $id ? NotificationTemplate::findOrFail($id) : null;
        $this->templateId = $t?->id;
        $this->tpl = ['event' => $t?->event ?? 'application_status', 'channel' => $t?->channel ?? 'sms', 'subject' => $t?->subject ?? '', 'subject_ur' => $t?->subject_ur ?? '',
            'body' => $t?->body ?? '', 'body_ur' => $t?->body_ur ?? '', 'is_active' => (bool) ($t?->is_active ?? true)];
        $this->resetValidation();
        $this->showTemplate = true;
    }

    public function saveTemplate(): void
    {
        $this->need('content.manage');
        $this->validate([
            'tpl.channel' => 'required|in:sms,email,whatsapp,push,in_app',
            'tpl.event' => ['required', Rule::unique('notification_templates', 'event')->where('tenant_id', TenantContext::id())->where('channel', $this->tpl['channel'] ?? '')->ignore($this->templateId)],
            'tpl.subject' => 'nullable|max:200', 'tpl.subject_ur' => 'nullable|max:200', 'tpl.body' => 'required|max:2000', 'tpl.body_ur' => 'nullable|max:2000',
        ], ['tpl.event.unique' => __('A template for this event and channel already exists.')]);
        if (! in_array($this->tpl['event'], self::EVENTS, true)) {
            $this->addError('tpl.event', __('Unknown event.'));

            return;
        }
        $t = $this->templateId ? NotificationTemplate::findOrFail($this->templateId) : new NotificationTemplate;
        $t->fill($this->tpl);
        $new = ! $t->exists;
        $new ? null : Auditor::changes('content.template_updated', $t);
        $t->save();
        if ($new) {
            Auditor::record('content.template_created', $t, [], ['event' => $t->event, 'channel' => $t->channel]);
        }
        $this->showTemplate = false;
        $this->toast(__('Template saved.'));
    }

    public function render()
    {
        $this->need('content.manage');

        return view('livewire.ops.admin.content', [
            'items' => $this->tab === 'content' ? ContentItem::orderBy('type')->orderBy('sort')->get() : collect(),
            'templates' => $this->tab === 'templates' ? NotificationTemplate::orderBy('event')->orderBy('channel')->get() : collect(),
            'logs' => $this->tab === 'log' ? NotificationLog::with(['user'])->where('tenant_id', TenantContext::id())
                ->when($this->logChannel, fn ($q) => $q->where('channel', $this->logChannel))
                ->when($this->logStatus, fn ($q) => $q->where('status', $this->logStatus))
                ->latest('id')->paginate(20) : null,
            'events' => array_combine(self::EVENTS, array_map(fn ($e) => \Illuminate\Support\Str::headline($e), self::EVENTS)),
        ])->title(__('Content & templates'));
    }

    /** Defensive masking: OTP codes are never sent through templates, but mask any 6-digit code in logs. */
    public static function safeBody(?string $body): string
    {
        return preg_replace('/\b\d{6}\b/', '••••••', (string) $body);
    }
}
