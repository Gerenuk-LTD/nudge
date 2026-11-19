<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Notifications\EmailAddressChanged;
use Gerenuk\Nudge\Actions\Workspaces\RemoveUserFromWorkspaces;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $previousEmail = $request->user()->getOriginal('email');

        $request->user()->fill($request->safe()->except('current_password'));

        // Stored lowercased, as login looks it up. A change of case alone isn't a new address, so it needs no
        // password (the request compares lowercased) and keeps the address verified.
        $request->user()->email = mb_strtolower(trim($request->user()->email));
        $emailChanged = $request->user()->email !== mb_strtolower(trim((string) $previousEmail));

        if ($emailChanged) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        if ($emailChanged) {
            $request->user()->sendEmailVerificationNotification();

            Notification::route('mail', $previousEmail)->notify(new EmailAddressChanged($request->user()->email));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     *
     * Users are first removed from their workspaces through the core's rules. Anyone who owns a workspace, or is the
     * only owner of one, is refused with a validation error and stays signed in.
     */
    public function destroy(ProfileDeleteRequest $request, RemoveUserFromWorkspaces $removeFromWorkspaces): RedirectResponse
    {
        $user = $request->user();

        // The workspace check runs first, so a refused user stays signed in with nothing changed. Logging out happens
        // before the delete because logout saves a fresh remember token, which would re-create a deleted user.
        DB::transaction(function () use ($user, $removeFromWorkspaces): void {
            $removeFromWorkspaces->handle($user);
            Auth::logout();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
