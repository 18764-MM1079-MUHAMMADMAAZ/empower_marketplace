<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function logout(Request $request): RedirectResponse|View
    {
        $user = $request->user();
        // Anyone who may have an Empower LMS session (spec §5: logging out of Marketplace also ends it).
        $endLmsSession = $user && filled(config('oidc.moodle.client_id'))
            && ($user->moodle_user_id !== null || $user->hasLmsAccess());

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($endLmsSession) {
            return view('auth.lms-logout', ['logoutUrl' => config('oidc.moodle.frontchannel_logout_uri')]);
        }

        return redirect()->route('home');
    }
}
