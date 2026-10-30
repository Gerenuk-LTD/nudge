<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'sessions' => $this->sessions($request),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Sign the user out everywhere except this browser, after checking their password.
     */
    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out of your other sessions.')]);

        return back();
    }

    /**
     * Where the user is signed in, newest first, when sessions are stored in the database. Other drivers cannot list
     * a user's sessions, so the page leaves the section out.
     *
     * @return list<array{id: string, browser: string|null, platform: string|null, ip_address: string|null, last_active_at: string, current: bool}>|null
     */
    private function sessions(Request $request): ?array
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return array_values(DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session) => [
                'id' => hash('sha256', $session->id),
                ...$this->device((string) $session->user_agent),
                'ip_address' => is_string($session->ip_address) ? $session->ip_address : null,
                'last_active_at' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
                'current' => $session->id === $request->session()->getId(),
            ])
            ->values()
            ->all());
    }

    /**
     * A browser and platform name from a user agent, good enough to recognise a device by.
     *
     * @return array{browser: string|null, platform: string|null}
     */
    private function device(string $userAgent): array
    {
        $first = fn (array $patterns): ?string => collect($patterns)->first(fn (string $name, string $pattern) => preg_match($pattern, $userAgent) === 1);

        return [
            'browser' => $first(['/Edg\//' => 'Edge', '/OPR\//' => 'Opera', '/Firefox\//' => 'Firefox', '/Chrome\//' => 'Chrome', '/Safari\//' => 'Safari']),
            'platform' => $first(['/iPhone/' => 'iPhone', '/iPad/' => 'iPad', '/Android/' => 'Android', '/Mac OS X/' => 'Mac', '/Windows/' => 'Windows', '/Linux/' => 'Linux']),
        ];
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
