<?php

namespace App\Services\WordPress;

use RuntimeException;

/**
 * The film-tour WordPress sites the Partners dashboard can manage.
 *
 * There is more than one tour and each has its own WordPress install, its own
 * credentials and its own taxonomy term ids — "Australia" is term 2 on one site
 * and term 6 on another. Nothing about one site may be assumed of another, so a
 * site is chosen explicitly on every request and carried through to the
 * repository, the taxonomy cache and the wp-admin deep links.
 *
 * Choosing is deliberately strict for writes. A missing or unknown key is an
 * error, never a fallback to a default: writing a partner to the wrong tour's
 * public site is far worse than a request that refuses to run.
 */
class FilmSites
{
    /** Built repositories, so one request talking to a site twice opens one client. */
    private array $built = [];

    /**
     * Every site this installation knows about, keyed by the key used on the wire.
     *
     * In mock mode there is exactly one pseudo-site: the mock store keeps its
     * state under fixed cache keys, so three of them would be the same store
     * wearing three labels, which is worse than being honest about it.
     *
     * @return array<string, array{key: string, label: string, url: ?string, host: ?string, configured: bool}>
     */
    public function all(): array
    {
        if ($this->isMock()) {
            return ['mock' => [
                'key' => 'mock',
                'label' => 'Mock film site',
                'url' => null,
                'host' => null,
                'configured' => true,
            ]];
        }

        $out = [];

        foreach ((array) config('services.wordpress.sites', []) as $key => $site) {
            $url = is_string($site['url'] ?? null) ? rtrim($site['url'], '/') : null;

            $out[$key] = [
                'key' => $key,
                'label' => $site['label'] ?? $key,
                'url' => $url,
                'host' => $url ? parse_url($url, PHP_URL_HOST) : null,
                'configured' => filled($site['url'] ?? null)
                    && filled($site['user'] ?? null)
                    && filled($site['app_password'] ?? null),
            ];
        }

        return $out;
    }

    /** @return array<string, array{key: string, label: string, url: ?string, host: ?string, configured: bool}> */
    public function configured(): array
    {
        return array_filter($this->all(), fn (array $s) => $s['configured']);
    }

    public function has(string $key): bool
    {
        return isset($this->configured()[$key]);
    }

    /**
     * The site to show when the request names none.
     *
     * Only ever used for opening the dashboard. A write never reaches for this.
     */
    public function default(): ?string
    {
        return array_key_first($this->configured());
    }

    /** The site a film publishes to, matched on host so a trailing slash or scheme cannot miss. */
    public function forUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = strtolower((string) (parse_url(
            str_contains($url, '//') ? $url : 'https://'.$url,
            PHP_URL_HOST
        ) ?: $url));

        $host = preg_replace('/^www\./', '', $host);

        foreach ($this->configured() as $key => $site) {
            if ($site['host'] && preg_replace('/^www\./', '', strtolower($site['host'])) === $host) {
                return $key;
            }
        }

        return null;
    }

    public function label(string $key): string
    {
        return $this->all()[$key]['label'] ?? $key;
    }

    /** The site address, for deep links into wp-admin. Never the credentials that go with it. */
    public function url(string $key): ?string
    {
        return $this->all()[$key]['url'] ?? null;
    }

    public function isMock(): bool
    {
        return config('services.wordpress.sponsor_driver') === 'mock';
    }

    /**
     * The repository for one site.
     *
     * @throws RuntimeException when the key names no configured site
     */
    public function repository(string $key): SponsorRepository
    {
        if (isset($this->built[$key])) {
            return $this->built[$key];
        }

        if ($this->isMock()) {
            return $this->built[$key] = new MockSponsorService;
        }

        $site = config("services.wordpress.sites.{$key}");

        if (! is_array($site)) {
            throw new RuntimeException("'{$key}' is not a film site this app knows about.");
        }

        if (blank($site['url']) || blank($site['user']) || blank($site['app_password'])) {
            $env = strtoupper($key);

            throw new RuntimeException(
                "No WordPress credentials configured for {$this->label($key)}. Set WP_{$env}_URL, "
                ."WP_{$env}_USER and WP_{$env}_APP_PASSWORD, or set SPONSOR_DRIVER=mock for local work."
            );
        }

        return $this->built[$key] = new WordPressSponsorService(
            $site['url'],
            $site['user'],
            $site['app_password'],
        );
    }
}
