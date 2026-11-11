<?php

namespace App\Services\Notifications;

use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\Integrations\IntegrationManager;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Sends notifications. In-app notifications are stored in the database; external channels
 * (SMS / email / WhatsApp) are delivered through the simulated messaging providers and logged
 * to notification_logs, with automatic fallback to SMS when a channel fails (DFS §5.1).
 */
class Notifier
{
    public function toUser(User $user, string $event, array $data): void
    {
        $user->notify(new InAppNotification($event, $data));
    }

    /**
     * Notify a customer on every configured channel for the event.
     *
     * @param  array  $data  title, body (fallback text) and template variables
     */
    public function toCustomer(Customer $customer, string $event, array $data, array $channels = ['in_app', 'sms']): void
    {
        $locale = $customer->preferred_locale ?: 'en';
        $templates = NotificationTemplate::where('event', $event)->where('is_active', true)->get()->keyBy('channel');

        foreach ($channels as $channel) {
            $tpl = $templates->get($channel);
            $subject = $this->render(($locale === 'ur' ? $tpl?->subject_ur : null) ?? $tpl?->subject ?? ($data['title'] ?? Str::headline($event)), $data);
            $body = $this->render(($locale === 'ur' ? $tpl?->body_ur : null) ?? $tpl?->body ?? ($data['body'] ?? ''), $data);

            if ($channel === 'in_app') {
                if ($customer->user) {
                    $customer->user->notify(new InAppNotification($event, ['title' => $subject, 'body' => $body, 'url' => $data['url'] ?? null]));
                }

                continue;
            }

            $this->deliverExternal($customer, $event, $channel, $subject, $body);
        }
    }

    private function deliverExternal(Customer $customer, string $event, string $channel, string $subject, string $body, ?NotificationLog $fallbackOf = null): void
    {
        $recipient = $channel === 'email' ? $customer->email : $customer->mobile;
        if (! $recipient) {
            if ($channel !== 'sms') {
                $this->deliverExternal($customer, $event, 'sms', $subject, $body);
            }

            return;
        }

        $result = app(IntegrationManager::class)->message($channel, $recipient, $subject, $body);
        $log = NotificationLog::create([
            'tenant_id' => TenantContext::id() ?? $customer->tenant_id,
            'user_id' => $customer->user?->id,
            'event' => $event,
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => $result->successful() ? 'sent' : 'failed',
            'provider_ref' => $result->providerRef,
            'fallback_of_id' => $fallbackOf?->id,
            'simulated' => $result->simulated,
        ]);

        if (! $result->successful() && $channel !== 'sms') {
            $this->deliverExternal($customer, $event, 'sms', $subject, $body, $log);
        }
    }

    private function render(string $text, array $data): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($data[$m[1]] ?? $m[0]), $text);
    }
}
