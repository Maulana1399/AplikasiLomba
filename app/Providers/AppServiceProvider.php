<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Support\ActiveEventContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActiveEventContext::class, function () {
            return new ActiveEventContext;
        });
    }

    public function boot(): void
    {
        // AplikasiLomba: no-auth internal LAN app — bypass all gates
        Gate::before(function (?User $user) {
            return true;
        });

        // --- Platform abilities (users.role) ---

        Gate::define('view-master-data', fn (User $user) => $user->hasAnyRole(
            Role::SuperAdmin,
        ));

        Gate::define('manage-master-data', fn (User $user) => $user->hasAnyRole(
            Role::SuperAdmin,
        ));

        Gate::define('manage-events', fn (User $user) => $user->hasAnyRole(
            Role::SuperAdmin, Role::Admin,
        ));

        Gate::define('manage-users', fn (User $user) => $user->hasAnyRole(
            Role::SuperAdmin,
        ));
    }
}
