<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return redirect()->route('home')
                ->with('toast', 'You are not allowed to view this page. Please login to continue!')
                ->with('toast_type', 'error');
        }

        return $next($request);
    }
}
