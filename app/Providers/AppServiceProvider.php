<?php

namespace App\Providers;

use App\Models\Events;
use App\Models\ExportLog;
use App\Models\Films;
use App\Models\Location;
use App\Models\PickaWinner;
use App\Models\Prize;
use App\Models\SignUpForm;
use App\Models\User;
use App\Observers\ActivityObserver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** User-meaningful models audited for create/update/delete activity. */
    private const AUDITED_MODELS = [
        Events::class,
        Location::class,
        Films::class,
        Prize::class,
        PickaWinner::class,
        SignUpForm::class,
        User::class,
    ];

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
        Vite::prefetch(concurrency: 3);

        // Activity logging: audit create/update/delete on user-meaningful models.
        foreach (self::AUDITED_MODELS as $model) {
            $model::observe(ActivityObserver::class);
        }

        // Activity logging: login / logout / failed login.
        Event::listen(Login::class, function (Login $event) {
            ExportLog::recordActivity('login', [
                'user_id' => $event->user?->id,
                'user_name_snapshot' => $event->user?->name,
                'user_email_snapshot' => $event->user?->email,
            ]);
        });

        Event::listen(Logout::class, function (Logout $event) {
            ExportLog::recordActivity('logout', [
                'user_id' => $event->user?->id,
                'user_name_snapshot' => $event->user?->name,
                'user_email_snapshot' => $event->user?->email,
            ]);
        });

        Event::listen(Failed::class, function (Failed $event) {
            ExportLog::recordActivity('login_failed', [
                'user_email_snapshot' => $event->credentials['email'] ?? null,
                'status' => 'failed',
            ]);
        });
    }
}
