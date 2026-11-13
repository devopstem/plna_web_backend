<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthActivityService;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuthActivityService $authActivity,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->pendingLoginIsValid($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:64'],
        ], [
            'code.required' => 'Введите код из приложения.',
        ]);

        if (! $this->pendingLoginIsValid($request)) {
            return redirect()->route('login')
                ->with('status', 'Сессия двухфакторной проверки истекла. Войдите снова.');
        }

        $userId = $request->session()->get('login.id');
        $user = User::query()->find($userId);

        if (! $user || ! $this->twoFactor->isEnabled($user)) {
            $this->forgetPendingLogin($request);

            return redirect()->route('login');
        }

        if (! $this->twoFactor->verify($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Неверный код. Попробуйте ещё раз или используйте recovery-код.',
            ]);
        }

        Auth::login($user, (bool) $request->session()->pull('login.remember', false));
        $this->forgetPendingLogin($request);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        $this->authActivity->logLogin($request, $user, 'success');
        $this->authActivity->startSession($request, $user);

        return redirect()->route('dashboard');
    }

    private function pendingLoginIsValid(Request $request): bool
    {
        if (! $request->session()->has('login.id')) {
            return false;
        }

        $expiresAt = (int) $request->session()->get('login.id_expires_at', 0);

        if ($expiresAt <= 0 || now()->getTimestamp() > $expiresAt) {
            $this->forgetPendingLogin($request);

            return false;
        }

        return true;
    }

    private function forgetPendingLogin(Request $request): void
    {
        $request->session()->forget(['login.id', 'login.remember', 'login.id_expires_at']);
    }
}
