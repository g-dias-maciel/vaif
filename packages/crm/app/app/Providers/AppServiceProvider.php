<?php

namespace App\Providers;

use App\Models\Artist;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The artist the current user is operating on — shown in the sidebar.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            $artist = null;

            if ($user) {
                if ($user->isArtist()) {
                    $artist = $user->artist;
                } elseif ($id = session('staff_artist_id')) {
                    $artist = Artist::find($id);
                }
            }

            $view->with('navArtist', $artist);
        });
    }
}
