<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Cooler;
use App\Models\Department;
use App\Models\Placement;
use App\Models\Product;
use App\Models\Shelf;
use App\Models\Stand;
use App\Models\Store;
use App\Observers\CoolerObserver;
use App\Observers\DepartmentObserver;
use App\Observers\PlacementObserver;
use App\Observers\ProductObserver;
use App\Observers\ShelfObserver;
use App\Observers\StandObserver;
use App\Observers\StoreObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        Vite::prefetch(concurrency: 3);

        Store::observe(StoreObserver::class);
        Department::observe(DepartmentObserver::class);
        Shelf::observe(ShelfObserver::class);
        Cooler::observe(CoolerObserver::class);
        Stand::observe(StandObserver::class);
        Placement::observe(PlacementObserver::class);
        Product::observe(ProductObserver::class);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $loginId = $request->session()->get('login.id');

            return Limit::perMinute(5)->by((string) ($loginId ?: $request->ip()));
        });
    }
}
