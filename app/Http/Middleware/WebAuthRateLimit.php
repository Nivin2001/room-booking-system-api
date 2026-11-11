<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class WebAuthRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->input('email');
        $identity = is_string($email) ? mb_strtolower(trim($email)) : '';
        $key = 'web-auth:'.hash('sha256', $identity.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $route = $request->routeIs('register.store') ? 'register' : 'login';

            return redirect()->route($route)
                ->withErrors(['email' => 'تم تجاوز عدد المحاولات المسموح. انتظر قليلًا ثم حاول مجددًا.'])
                ->withInput($request->except(['password', 'password_confirmation']))
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
        $response = $next($request);

        if (Auth::guard('web')->check()) {
            RateLimiter::clear($key);
        }

        return $response;
    }
}
