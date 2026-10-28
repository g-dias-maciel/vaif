<?php

namespace App\Providers;

use App\Models\CalendarEntry;
use App\Models\Lead;
use App\Models\Project;
use App\Policies\BookingPolicy;
use App\Policies\LeadPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Lead::class => LeadPolicy::class,
        Project::class => ProjectPolicy::class,
        CalendarEntry::class => BookingPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
