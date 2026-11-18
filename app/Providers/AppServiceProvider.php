<?php

namespace App\Providers;

use App\Listeners\AddAlwaysCcRecipient;
use App\Models\Events;
use App\Models\ExportLog;
use App\Models\Films;
use App\Models\Location;
use App\Models\PickaWinner;
use App\Models\Prize;
use App\Models\SignUpForm;
use App\Models\User;
use App\Observers\ActivityObserver;
use App\Services\WordPress\FilmSites;
use App\Services\WordPress\SponsorRepository;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        // Sponsors live in WordPress, not here, and there is one WordPress per
        // tour. FilmSites holds the list and builds the client for whichever one
        // a request names; whether that is a real site or the mock stand-in is
        // decided explicitly by SPONSOR_DRIVER and never inferred from a failure.
        $this->app->singleton(FilmSites::class);

        // Resolving the interface with no site named means the default one — the
        // first that is configured. Only ever right for reading; the dashboard
        // names its site on every write, so nothing lands on a site by accident.
        $this->app->bind(SponsorRepository::class, function ($app) {
            $sites = $app->make(FilmSites::class);
            $key = $sites->default();

            if ($key === null) {
                throw new RuntimeException(
                    'No film site is configured for partners. Set WP_WAFT_URL, WP_WAFT_USER and '
                    .'WP_WAFT_APP_PASSWORD (or another site\'s three), or set SPONSOR_DRIVER=mock '
                    .'for local work.'
                );
            }

            return $sites->repository($key);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Standing CC on outgoing mail (mail.always_cc). Registered on the send
        // event rather than per-Mailable so a mail added later cannot miss it.
        Event::listen(MessageSending::class, AddAlwaysCcRecipient::class);

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
