<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WebAuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'يرجى إدخال البريد الإلكتروني.',
            'email.email' => 'يرجى إدخال بريد إلكتروني صحيح.',
            'password.required' => 'يرجى إدخال كلمة المرور.',
        ]);

        $guard = Auth::guard('web');

        if (! $guard->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'تعذر تسجيل الدخول بهذه البيانات. تحقق منها وحاول مجددًا.',
            ]);
        }

        $user = $guard->user();
        if (! $user || ! in_array($user->role, ['customer', 'staff', 'admin'], true)) {
            $guard->logout();
            $request->session()->regenerate();

            throw ValidationException::withMessages([
                'email' => 'تعذر تسجيل الدخول بهذه البيانات. تحقق منها وحاول مجددًا.',
            ]);
        }

        $request->session()->regenerate();

        return $this->redirectToSafeIntendedUrl($request, $user, $this->dashboardRouteFor($user));
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'يرجى إدخال الاسم.',
            'name.max' => 'يجب ألا يتجاوز الاسم 255 حرفًا.',
            'email.required' => 'يرجى إدخال البريد الإلكتروني.',
            'email.email' => 'يرجى إدخال بريد إلكتروني صحيح.',
            'password.required' => 'يرجى إدخال كلمة المرور.',
            'password.min' => 'يجب ألا تقل كلمة المرور عن 8 أحرف.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
        ]);

        try {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'customer',
            ]);
        } catch (QueryException $exception) {
            Log::warning('Web customer registration could not be completed.', [
                'exception' => class_basename($exception),
            ]);

            throw ValidationException::withMessages([
                'email' => 'تعذر إنشاء الحساب بهذه البيانات. تحقق منها وحاول مجددًا.',
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->redirectToSafeIntendedUrl($request, $user, 'spaces.index');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('spaces.index')->with('auth_notice', 'تم تسجيل الخروج بنجاح.');
    }

    private function redirectToSafeIntendedUrl(Request $request, User $user, string $fallbackRoute)
    {
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && ! preg_match('/[\r\n\\\\]/', $intended)) {
            $parts = parse_url($intended);
            if (is_array($parts)) {
                $sameHost = ! isset($parts['host'])
                    || strcasecmp($parts['host'], $request->getHost()) === 0;
                $samePort = ! isset($parts['port']) || $parts['port'] === $request->getPort();
                $safeScheme = ! isset($parts['scheme'])
                    || in_array(strtolower($parts['scheme']), ['http', 'https'], true);
                $path = $parts['path'] ?? null;

                if (is_string($path)
                    && str_starts_with($path, '/')
                    && ! str_starts_with($path, '//')
                    && $sameHost
                    && $samePort
                    && $safeScheme
                    && ! isset($parts['user'])
                    && ! isset($parts['pass'])
                    && $this->intendedPathIsAllowedForRole($path, $user->role)) {
                    $destination = $path;
                    if (isset($parts['query'])) {
                        $destination .= '?'.$parts['query'];
                    }

                    return redirect()->to($destination);
                }
            }
        }

        return redirect()->route($fallbackRoute);
    }

    private function dashboardRouteFor(User $user): string
    {
        return match ($user->role) {
            'customer' => 'customer.bookings.index',
            'staff' => 'staff.dashboard',
            'admin' => 'admin.dashboard',
            default => 'spaces.index',
        };
    }

    private function intendedPathIsAllowedForRole(string $path, string $role): bool
    {
        if ($path === '/' || preg_match('#^/spaces/\d+$#', $path)) {
            return true;
        }

        return match ($role) {
            'customer' => (bool) preg_match('#^/(?:my-bookings(?:/\d+)?|spaces/\d+/book|booking-confirmation/\d+(?:/payment-(?:success|cancelled))?)$#', $path),
            'staff' => str_starts_with($path, '/staff/'),
            'admin' => str_starts_with($path, '/admin/'),
            default => false,
        };
    }
}
