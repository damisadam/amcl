<?php

namespace App\Http\Middleware;

use App\Models\UserDevice;
use Closure;
use Illuminate\Http\Request;

/** Device management (Wallet §9 / DFS §6.4): records the devices a user signs in from; revoked devices are logged out. */
class TrackDevice
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $request->is('livewire/*')) {
            $fp = hash('sha256', substr((string) $request->userAgent(), 0, 300).'|'.$user->id);
            $device = UserDevice::firstOrNew(['user_id' => $user->id, 'fingerprint' => $fp]);
            if ($device->exists && $device->revoked_at) {
                auth()->logout();
                $request->session()->invalidate();

                return redirect('/')->with('error', 'This device has been signed out from your account settings.');
            }
            if (! $device->exists || $device->last_seen_at?->lt(now()->subMinutes(10))) {
                $device->fill(['tenant_id' => $user->tenant_id, 'label' => self::label((string) $request->userAgent()), 'user_agent' => substr((string) $request->userAgent(), 0, 500),
                    'ip' => $request->ip(), 'last_seen_at' => now()])->save();
            }
        }

        return $next($request);
    }

    public static function label(string $ua): string
    {
        $os = match (true) { str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'Linux') => 'Linux', default => 'Device' };
        $br = match (true) { str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Firefox') => 'Firefox', str_contains($ua, 'Safari') => 'Safari', default => 'Browser' };

        return "$br on $os";
    }
}
