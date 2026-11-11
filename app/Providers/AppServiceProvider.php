<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\HrMechanism;
use App\Models\User;
use App\Policies\HrMechanismPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(HrMechanism::class, HrMechanismPolicy::class);

        // The mechanisms list: everyone who can sign in may open it, scoped to what they may see.
        Gate::define('view-mechanisms', fn (User $user) => true);

        // Which agency each Agency account belongs to: PSED sets it.
        Gate::define('manage-agency-accounts', fn (User $user) => $user->isPsed());

        // The history of who did what, personal data included: the PSED Chief and the Director.
        Gate::define('view-audit-trail', fn (User $user) => $user->isChief() || $user->role === UserRole::Director);
    }
}
