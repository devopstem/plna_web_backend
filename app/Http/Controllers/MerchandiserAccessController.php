<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MerchandiserAccessController extends Controller
{
    public function mobileOnly(Request $request): Response
    {
        $user = $request->user();
        $roleLabel = $user?->isIntegrator() ? 'Программист' : 'Мерчандайзер';

        return Inertia::render('Auth/MobileOnly', [
            'email' => $user?->email,
            'roleLabel' => $roleLabel,
        ]);
    }
}
