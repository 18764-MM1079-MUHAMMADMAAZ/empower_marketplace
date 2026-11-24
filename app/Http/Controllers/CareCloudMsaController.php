<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * "Read the CareCloud MSA" in the checkout's Terms & Conditions popup links here rather than
 * straight to the MSA: opening it is recorded in the session first, and checkout won't accept the
 * Terms & Conditions until it has been (⚡portal.blade.php's validateTermsAcceptance()).
 */
class CareCloudMsaController extends Controller
{
    public const SESSION_KEY = 'carecloud_msa_opened_at';

    public function __invoke(): RedirectResponse
    {
        $url = config('services.carecloud.msa_url');

        abort_if(blank($url) || $url === '#', 404);

        session()->put(self::SESSION_KEY, now()->toIso8601String());

        return redirect()->away($url);
    }
}
