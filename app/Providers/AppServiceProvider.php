<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Services\Event\EventPermissionService;
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
        $permission = $this->app->make(EventPermissionService::class);

        // AplikasiLomba: no-auth internal LAN app — bypass all gates
        Gate::before(function (?User $user) {
            return true;
        });

        $eventAbility = function (User $user, string $ability) use ($permission): bool {
            if ($user->role === Role::Admin) {
                return true;
            }

            return $permission->allows($user, $ability);
        };

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

        // --- Event abilities (Permission Engine) ---

        Gate::define('view-dashboard', fn (User $user) => $eventAbility($user, 'view-dashboard'));

        Gate::define('manage-registration', fn (User $user) => $eventAbility($user, 'manage-registration'));

        Gate::define('manage-participants', fn (User $user) => $eventAbility($user, 'manage-participants'));

        Gate::define('manage-attendance', fn (User $user) => $eventAbility($user, 'manage-attendance'));

        Gate::define('manage-sessions', fn (User $user) => $eventAbility($user, 'manage-sessions'));

        Gate::define('manage-qr-labels', fn (User $user) => $eventAbility($user, 'manage-qr-labels'));

        Gate::define('manage-secretariat', fn (User $user) => $eventAbility($user, 'manage-secretariat'));

        Gate::define('manage-import', fn (User $user) => $eventAbility($user, 'manage-import'));

        Gate::define('view-reports', fn (User $user) => $eventAbility($user, 'view-reports'));

        Gate::define('manage-pengajian', fn (User $user) => $eventAbility($user, 'manage-pengajian'));

        Gate::define('view-activity-log', fn (User $user) => $eventAbility($user, 'view-activity-log'));

        Gate::define('manage-matches', fn (User $user) => $eventAbility($user, 'manage-matches'));

        Gate::define('manage-officials', fn (User $user) => $eventAbility($user, 'manage-officials'));

        Gate::define('submit-result', fn (User $user) => $eventAbility($user, 'submit-result'));
    }
}
