<?php
/**
 * Plugin Name: Pick a Winner Banner
 * Description: Mirrors the event banner from the Pick a Winner app onto this site. Read-only: the app owns the banner, WordPress just displays it.
 * Version:     0.1.1
 * Author:      Fence
 */

if (! defined('ABSPATH')) {
    exit; // Loaded outside WordPress.
}

const PAW_OPT      = 'paw_banner_options';
const PAW_CACHE    = 'paw_banner_payload';
const PAW_TIMEOUT  = 10; // Seconds. The app is usually local to the venue network.

/**
 * Saved settings, with defaults.
 */
function paw_options() {
    return wp_parse_args(get_option(PAW_OPT, []), [
        'base_url'      => '',
        'event_uuid'    => '',
        'cache_minutes' => 5,
    ]);
}

/**
 * Force absolute URLs onto the scheme of the configured App URL.
 *
 * The app builds URLs from the incoming request, so behind a reverse proxy or a
 * tunnel that terminates TLS it hands back http:// even though the site is
 * served over https://. Rendering that on an HTTPS page gets the image blocked
 * as mixed content, so trust the scheme the admin configured instead.
 */
function paw_match_scheme($url, $base) {
    if (! is_string($url) || $url === '' || strpos($base, 'https://') !== 0) {
        return $url;
    }

    return set_url_scheme($url, 'https');
}

/**
 * Fetch the banner payload from the app.
 *
 * Server-side on purpose: wp_remote_get is not subject to CORS, and it keeps the
 * event uuid out of the page source.
 *
 * @param bool $fresh Skip the transient (used by the Test connection button).
 * @return array|WP_Error Decoded payload, or WP_Error describing the failure.
 */
function paw_fetch($fresh = false) {
    $opt = paw_options();

    if ($opt['base_url'] === '' || $opt['event_uuid'] === '') {
        return new WP_Error('paw_unconfigured', 'Set the app URL and event UUID first.');
    }

    if (! $fresh) {
        $cached = get_transient(PAW_CACHE);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $url = trailingslashit($opt['base_url']) . 'api/wp/event/' . rawurlencode($opt['event_uuid']) . '/banner';

    $res = wp_remote_get($url, [
        'timeout' => PAW_TIMEOUT,
        'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($res)) {
        return $res; // DNS, TLS, timeout — the message is already useful.
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code !== 200) {
        // 404 here almost always means the uuid is wrong, not that the app is down.
        return new WP_Error('paw_http_' . $code, "App replied HTTP {$code}.", $body);
    }

    $data = json_decode($body, true);
    if (! is_array($data)) {
        return new WP_Error('paw_bad_json', 'App reply was not JSON.', $body);
    }

    foreach (['banner_url', 'logo_url', 'signup_url'] as $key) {
        if (isset($data[$key])) {
            $data[$key] = paw_match_scheme($data[$key], $opt['base_url']);
        }
    }

    set_transient(PAW_CACHE, $data, max(1, (int) $opt['cache_minutes']) * MINUTE_IN_SECONDS);

    return $data;
}

/**
 * [paw_banner] — renders the banner, or nothing at all if the event is disabled
 * or the app is unreachable. A page should never show an error to a visitor.
 */
function paw_banner_shortcode($atts) {
    $atts = shortcode_atts(['link' => 'signup'], $atts, 'paw_banner');
    $data = paw_fetch();

    if (is_wp_error($data) || empty($data['is_enabled']) || empty($data['banner_url'])) {
        return '';
    }

    $img = sprintf(
        '<img src="%s" alt="%s" style="max-width:100%%;height:auto;" />',
        esc_url($data['banner_url']),
        esc_attr($data['event_name'] ?? '')
    );

    // Link the banner at the sign-up form unless the event has closed.
    if ($atts['link'] === 'signup' && ! empty($data['signup_url']) && empty($data['is_signup_closed'])) {
        $img = sprintf('<a href="%s">%s</a>', esc_url($data['signup_url']), $img);
    }

    return '<div class="paw-banner">' . $img . '</div>';
}
add_shortcode('paw_banner', 'paw_banner_shortcode');

/* -------------------------------------------------------------------------
 * Settings screen
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_options_page('Pick a Winner Banner', 'Pick a Winner Banner', 'manage_options', 'paw-banner', 'paw_settings_page');
});

add_action('admin_init', function () {
    register_setting('paw_banner_group', PAW_OPT, 'paw_sanitize');
});

function paw_sanitize($input) {
    delete_transient(PAW_CACHE); // Settings changed — never serve the old event.

    return [
        'base_url'      => esc_url_raw(rtrim(trim($input['base_url'] ?? ''), '/')),
        'event_uuid'    => sanitize_text_field($input['event_uuid'] ?? ''),
        'cache_minutes' => max(1, (int) ($input['cache_minutes'] ?? 5)),
    ];
}

/**
 * Test connection: fetch live, bypassing the cache, and show exactly what came back.
 */
add_action('admin_post_paw_test', function () {
    if (! current_user_can('manage_options') || ! check_admin_referer('paw_test')) {
        wp_die('Not allowed.');
    }

    $data   = paw_fetch(true);
    $result = is_wp_error($data)
        ? 'FAILED: ' . $data->get_error_message() . "\n" . (is_string($data->get_error_data()) ? substr($data->get_error_data(), 0, 500) : '')
        : "OK\n" . wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    set_transient('paw_test_result', $result, 60);
    wp_safe_redirect(admin_url('options-general.php?page=paw-banner'));
    exit;
});

function paw_settings_page() {
    $opt    = paw_options();
    $result = get_transient('paw_test_result');
    delete_transient('paw_test_result');
    ?>
    <div class="wrap">
        <h1>Pick a Winner Banner</h1>

        <form method="post" action="options.php">
            <?php settings_fields('paw_banner_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="paw_base">App URL</label></th>
                    <td>
                        <input id="paw_base" class="regular-text" type="url" name="<?php echo PAW_OPT; ?>[base_url]"
                               value="<?php echo esc_attr($opt['base_url']); ?>" placeholder="https://app.example.com" />
                        <p class="description">No trailing slash. Must be reachable from this web server, not just your laptop.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="paw_uuid">Event UUID</label></th>
                    <td>
                        <input id="paw_uuid" class="regular-text" type="text" name="<?php echo PAW_OPT; ?>[event_uuid]"
                               value="<?php echo esc_attr($opt['event_uuid']); ?>" />
                        <p class="description">The same uuid used in the app's /form/&lt;uuid&gt; sign-up link.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="paw_cache">Cache (minutes)</label></th>
                    <td><input id="paw_cache" type="number" min="1" name="<?php echo PAW_OPT; ?>[cache_minutes]"
                               value="<?php echo esc_attr($opt['cache_minutes']); ?>" /></td>
                </tr>
            </table>
            <?php submit_button('Save'); ?>
        </form>

        <hr />
        <h2>Test connection</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="paw_test" />
            <?php wp_nonce_field('paw_test'); ?>
            <?php submit_button('Test connection', 'secondary'); ?>
        </form>

        <?php if ($result) : ?>
            <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;overflow:auto;max-height:400px;"><?php echo esc_html($result); ?></pre>
        <?php endif; ?>

        <hr />
        <h2>Usage</h2>
        <p>Put <code>[paw_banner]</code> in any page, post or shortcode-capable block.
           Use <code>[paw_banner link="none"]</code> to render the image without linking to the sign-up form.</p>
    </div>
    <?php
}
