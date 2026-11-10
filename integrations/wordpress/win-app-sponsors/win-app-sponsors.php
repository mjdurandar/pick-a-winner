<?php
/**
 * Plugin Name: Win App Sponsors
 * Description: Lets the Win App manage this site's partner records over the REST API. Exposes the partner-group post type, its region/season taxonomies and its ACF fields — nothing else.
 * Version:     0.1.0
 * Author:      Fence
 */

if (! defined('ABSPATH')) {
    exit; // Loaded outside WordPress.
}

/**
 * The partner records on the adv-film theme.
 *
 * The theme registers this post type with show_in_rest => false, so every
 * authenticated REST call against it returns 404 and the Win App cannot create
 * or update a partner. This plugin flips that one flag, and nothing else about
 * the theme changes: the same records, the same fields, the same partners page.
 *
 * Works as a normal plugin (Plugins -> Add New -> Upload Plugin) or dropped
 * into wp-content/mu-plugins/ as a single file. Either way it loads before the
 * theme registers the post type, which is what makes the filters below apply.
 */
const WIN_APP_SPONSORS_POST_TYPE = 'partner-group';

/** The taxonomies the Win App sets when it writes a partner. */
const WIN_APP_SPONSORS_TAXONOMIES = ['region', 'season'];

/**
 * Publish the partner post type to the REST API.
 *
 * Scoped to one post type by name. Any other post type on the site passes
 * through untouched, so this cannot widen the API surface by accident.
 */
add_filter('register_post_type_args', function ($args, $post_type) {
    if ($post_type !== WIN_APP_SPONSORS_POST_TYPE) {
        return $args;
    }

    $args['show_in_rest'] = true;
    $args['rest_base'] = WIN_APP_SPONSORS_POST_TYPE;
    $args['rest_controller_class'] = 'WP_REST_Posts_Controller';

    // The Win App sends a title and relies on the editor capability check that
    // comes with it. Custom fields are what ACF reads and writes below.
    $args['supports'] = array_values(array_unique(array_merge(
        (array) ($args['supports'] ?? []),
        ['title', 'custom-fields']
    )));

    return $args;
}, 10, 2);

/**
 * Publish region and season too.
 *
 * Without this the taxonomy terms cannot be read (so the dashboard has no
 * region/season lists to choose from) and cannot be set on a write.
 */
add_filter('register_taxonomy_args', function ($args, $taxonomy) {
    if (! in_array($taxonomy, WIN_APP_SPONSORS_TAXONOMIES, true)) {
        return $args;
    }

    $args['show_in_rest'] = true;
    $args['rest_base'] = $taxonomy;

    return $args;
}, 10, 2);

/**
 * Read and write the partner's ACF fields through an `acf` key.
 *
 * ACF only exposes a field group over REST when that group is configured for
 * it, and the theme's group is not. Registering the field here keeps the
 * WordPress side authoritative about what a partner is while letting the Win
 * App send the three values it owns.
 *
 * Writes go through update_field() rather than post meta directly, so ACF
 * stores the reference the way the theme's templates expect to read it.
 */
add_action('rest_api_init', function () {
    if (! function_exists('get_fields')) {
        return; // ACF inactive; nothing to expose.
    }

    register_rest_field(WIN_APP_SPONSORS_POST_TYPE, 'acf', [
        'get_callback' => function ($post) {
            return get_fields($post['id']) ?: [];
        },
        'update_callback' => function ($value, $post) {
            if (! is_array($value)) {
                return;
            }

            // An allowlist, not a passthrough. `partner_group` is the theme's
            // own group field: a heading plus a repeater of partner rows, which
            // is how a partners page is actually built. Anything else the theme
            // keeps on the record stays out of reach of the API.
            $allowed = ['partner_group'];

            foreach ($value as $field => $field_value) {
                if (in_array($field, $allowed, true)) {
                    update_field($field, $field_value, $post->ID);
                }
            }
        },
        'schema' => [
            'description' => 'The partner_group field: { title, partners[] }, each partner row { image (attachment id), url, text, max_height, line_break }.',
            'type' => 'object',
        ],
    ]);
});
