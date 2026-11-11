<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Private per-user channel (notifications, own wallet events).
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => (int) $user->id === $id);

// Tenant operations channel: only active staff of that tenant.
Broadcast::channel('tenant.{tenantId}.ops', fn (User $user, int $tenantId) => $user->isStaff() && (int) $user->tenant_id === $tenantId && $user->status->value === 'active');

// Platform health alerts.
Broadcast::channel('platform.health', fn (User $user) => $user->isPlatform() && $user->hasPermission('platform.health.view'));
