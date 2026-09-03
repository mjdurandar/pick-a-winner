<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
                'warning' => $request->session()->get('warning'),
            ],
            // Master sheet tabs sitting on changes nobody has accepted yet. Shared
            // with every page so the alert follows an admin around rather than only
            // appearing on the screen they would have to think to visit.
            'sheetReview' => $this->sheetReview($request),
            'canManageMasterSheet' => (bool) $request->user()?->canManageMasterSheet(),
            'canManageSms' => (bool) $request->user()?->canManageSms(),
        ];
    }

    /**
     * Tabs awaiting review, for the nav badge and banner. Admin-only, and skipped
     * entirely for anyone else so a host never pays for the query.
     *
     * @return array{count: int, tabs: array<int, array<string, mixed>>}|null
     */
    protected function sheetReview(Request $request): ?array
    {
        // Gated on the owner account, not the admin role: an admin who cannot
        // open the sync screen would get a banner pointing at a 403.
        if (! $request->user()?->canManageMasterSheet()) {
            return null;
        }

        $sources = \App\Models\SheetSource::needingReview()->with('event')->get();

        if ($sources->isEmpty()) {
            return ['count' => 0, 'missing' => 0, 'tabs' => []];
        }

        return [
            'count' => $sources->count(),
            'missing' => $sources->sum(fn ($s) => $s->missingCount()),
            'tabs' => $sources->map(fn ($s) => [
                'id' => $s->id,
                'tab_name' => $s->tab_name,
                'event_name' => $s->event?->event_name,
                'pending' => $s->pendingChangeCount(),
                'missing' => $s->missingCount(),
            ])->all(),
        ];
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, \Closure $next)
    {
        if (! $request->user() && ! $request->is([
            'login',
            'register',
            'forgot-password',
            'reset-password/*',
            'form/*',
            'host-guide/*',
            'pickawinner',
            'pickawinner/*',
            'api/events/*/locations',
            // Read-only banner feed for the WordPress plugin (uuid-gated).
            'api/wp/*',
            // SMS short links: the whole point is a stranger tapping them.
            's/*',
            'picka-winner/verify',
            'prize/*',
            'prize',
        ])) {
            if ($request->header('X-Inertia')) {
                return Inertia::location(route('login'));
            }

            return redirect()->route('login');
        }

        return parent::handle($request, $next);
    }
}
