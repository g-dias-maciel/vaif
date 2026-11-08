<?php

namespace App\Providers;

use App\Models\CalendarEntry;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Prospect;
use App\Policies\BookingPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\LeadPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProspectPolicy;
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
        Prospect::class => ProspectPolicy::class,
        Campaign::class => CampaignPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
