<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Public self-registration is disabled.
 * Accounts are created by admins and bound to a tenant/role.
 */
class RegisteredUserController extends Controller
{
    public function create(): RedirectResponse
    {
        return redirect()->route('login');
    }

    public function store(Request $request): never
    {
        abort(403, 'Регистрация закрыта. Обратитесь к администратору.');
    }
}
