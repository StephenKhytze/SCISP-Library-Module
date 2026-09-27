<?php

namespace App\Providers;

use App\Services\Contracts\AuthServiceInterface;
use App\Services\Contracts\ProfileServiceInterface;
use App\Services\Fakes\FakeAuthService;
use App\Services\Fakes\FakeProfileService;
use Illuminate\Support\ServiceProvider;

class ExternalModuleServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(ProfileServiceInterface::class, function ($app) {
            $mode = env('PROFILE_SERVICE_MODE', 'fake');
            
            if ($mode === 'fake') {
                return new FakeProfileService();
            }
            
            // return new LiveProfileService(); // To be implemented later when API is ready
            throw new \Exception("Live ProfileService not implemented yet.");
        });

        $this->app->bind(AuthServiceInterface::class, function ($app) {
            $mode = env('AUTH_SERVICE_MODE', 'fake');
            
            if ($mode === 'fake') {
                return new FakeAuthService();
            }
            
            // return new LiveAuthService(); // To be implemented later when API is ready
            throw new \Exception("Live AuthService not implemented yet.");
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
