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
use App\Services\WordPress\MockSponsorService;
use App\Services\WordPress\SponsorRepository;
use App\Services\WordPress\WordPressSponsorService;
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
        // Sponsors live in WordPress, not here. Which WordPress — or the mock
        // stand-in used while the post type is still unreachable — is chosen
        // explicitly by SPONSOR_DRIVER and never inferred from a failure.
        $this->app->bind(SponsorRepository::class, function () {
            if (config('services.wordpress.sponsor_driver') === 'mock') {
                return new MockSponsorService;
            }

            $site = config('services.wordpress.waft');

            if (blank($site['url']) || blank($site['user']) || blank($site['app_password'])) {
                throw new RuntimeException(
                    'No WordPress credentials configured for sponsors. Set WP_WAFT_URL, WP_WAFT_USER and '
                    .'WP_WAFT_APP_PASSWORD, or set SPONSOR_DRIVER=mock for local work.'
                );
            }

            return new WordPressSponsorService($site['url'], $site['user'], $site['app_password']);
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
