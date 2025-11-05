<?php
/**
 * Plugin Name: Scheduled Content Block
 * Description: A simple container block that enables the easy scheduleing of content on WordPress pages or posts.
 * Version: 1.1.0
 * Requires PHP: 8.2
 * Author: h.b Plugins
 * Author URI: https://hancock.build
 * License: GPL-3.0-or-later
 * Text Domain: scheduled-content-block
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SCBLK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCBLK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SCBLK_OPTION_GROUP', 'scblk_settings' );
define( 'SCBLK_OPTION_VISIBILITY', 'scblk_visibility_roles' );
define( 'SCBLK_OPTION_BREEZE_ENABLE', 'scblk_breeze_enable' );
define( 'SCBLK_META_BREEZE_EVENTS', '_scblk_breeze_events' );
define( 'SCBLK_CRON_HOOK', 'scblk_breeze_cache_purge' );

/**
 * Retrieve a plugin option while falling back to a default value.
 */
function scblk_get_option( $option, $default ) {
        $value = get_option( $option, null );
        if ( null === $value ) {
                return $default;
        }

        return $value;
}

/**
 * Fetch scheduled Breeze events stored in post meta.
 */
function scblk_get_breeze_events_meta( $post_id ) {
        $events = get_post_meta( $post_id, SCBLK_META_BREEZE_EVENTS, true );
        if ( empty( $events ) || ! is_array( $events ) ) {
                return array();
        }

        return $events;
}

/**
 * Register the block (metadata) and attach server render callback.
 */
add_action( 'init', function() {
        register_block_type( __DIR__ . '/block', array(
                'render_callback' => 'scblk_render_callback',
        ) );
} );

/**
 * Inline the editor script to avoid HTTP fetch issues (e.g. WAF/404 serving HTML).
 */
add_action( 'enqueue_block_editor_assets', function () {
        $deps = array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-editor', 'wp-i18n', 'wp-block-editor' );
        $handle = 'scblk-editor';
        $path   = SCBLK_PLUGIN_DIR . 'block/editor.js';
        $url    = SCBLK_PLUGIN_URL . 'block/editor.js';

        if ( file_exists( $path ) ) {
                $version = filemtime( $path ) ?: '1.2.0';
                wp_enqueue_script( $handle, $url, $deps, $version, true );
        } else {
                wp_register_script( $handle, false, $deps, '1.2.0', true );
                wp_enqueue_script( $handle );

                $fallback_js = "(function(wp){var el=wp.element.createElement,be=wp.blockEditor||wp.editor;var Inner=be.InnerBlocks;wp.blocks.registerBlockType('h-b/scheduled-container',{edit:function(){return el('div',null,'Scheduled Container (inline fallback)');},save:function(){return el(Inner.Content,null);}});})(window.wp);";
                wp_add_inline_script( $handle, $fallback_js );
        }
});

/* -------------------------------------------------------
 * Core render logic (unchanged)
 * -----------------------------------------------------*/
function scblk_render_callback( $attributes, $content, $block ) {
	$defaults = array(
		'start'            => '',
		'end'              => '',
                'showPlaceholder'  => false,
                'placeholderText'  => '',
        );
	$atts = wp_parse_args( $attributes, $defaults );

	// Show in editor for authoring clarity.
	if ( is_admin() && function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return scblk_wrap_editor_notice( $atts, $content );
		}
	}

        // Allow bypass based on selected roles.
        if ( scblk_user_can_bypass_schedule() ) {
                return $content;
        }

	$now     = scblk_now_site_ts();
	$startTs = scblk_parse_site_ts( $atts['start'] );
	$endTs   = scblk_parse_site_ts( $atts['end'] );

	$hasStart = ( $atts['start'] !== '' && $startTs !== null );
	$hasEnd   = ( $atts['end']   !== '' && $endTs   !== null );

	// If user set a value but it failed to parse, hide (safe).
        $range_invalid = ( $hasStart && $hasEnd && $startTs !== null && $endTs !== null && $endTs < $startTs );
        $invalid = ( $atts['start'] !== '' && $startTs === null ) || ( $atts['end'] !== '' && $endTs === null ) || $range_invalid;
        if ( $invalid ) {
                return ! empty( $atts['showPlaceholder'] ) ? '<div class="scblk-placeholder" aria-hidden="true">' . esc_html( (string) $atts['placeholderText'] ) . '</div>' : '';
        }

	$visible = true;
	if ( $hasStart && $hasEnd ) {
		$visible = ( $now >= $startTs ) && ( $now <= $endTs );
	} elseif ( $hasStart ) {
		$visible = ( $now >= $startTs );
	} elseif ( $hasEnd ) {
		$visible = ( $now <= $endTs );
	}

	if ( $visible ) {
		return $content;
	}
	return ! empty( $atts['showPlaceholder'] ) ? '<div class="scblk-placeholder" aria-hidden="true">' . esc_html( (string) $atts['placeholderText'] ) . '</div>' : '';
}

/**
 * Parse an ISO date string:
 *  - with a timezone (Z or ±HH:MM): honor as absolute moment;
 *  - without timezone: interpret in site timezone.
 */
function scblk_parse_site_ts( $iso ) {
	if ( ! is_string( $iso ) || $iso === '' ) return null;
	$iso = trim( $iso );
	$has_tz = (bool) preg_match( '/(Z|[+\-]\d{2}:?\d{2})$/i', $iso );
	try {
		$dt = $has_tz ? new DateTime( $iso ) : new DateTime( $iso, wp_timezone() );
		return $dt->getTimestamp();
	} catch ( Exception $e ) {
		return null;
	}
}

/** Epoch now (timezone-agnostic) */
function scblk_now_site_ts() { return time(); }

/** Editor-only wrapper with a visible schedule badge */
function scblk_wrap_editor_notice( $atts, $content ) {
	$badge = scblk_schedule_badge_html( $atts, true );
	return '<div class="scblk-editor-wrap">' . $badge . $content . '</div>';
}

/** Render a small schedule badge (editor uses friendly format) */
function scblk_schedule_badge_html( $atts, $is_editor ) {
	$tz   = wp_timezone_string() ?: 'UTC';
	$fmt  = $is_editor ? 'F j Y \a\t g:ia' : ( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

	$start_ts = scblk_parse_site_ts( $atts['start'] );
	$end_ts   = scblk_parse_site_ts( $atts['end'] );

        $start = ($atts['start'] && $start_ts !== null) ? wp_date( $fmt, $start_ts ) : '—';
        $end   = ($atts['end'] && $end_ts   !== null)   ? wp_date( $fmt, $end_ts )   : '—';
        $context = $is_editor ? 'Editor preview' : 'Frontend view';

        return sprintf(
                '<div class="scblk-badge"><strong>Scheduled Content:</strong> %s | <strong>Start:</strong> %s | <strong>End:</strong> %s | <strong>TZ:</strong> %s</div>',
                esc_html( $context ), esc_html( $start ), esc_html( $end ), esc_html( $tz )
        );
}

/* =======================================================
 *  Optional Breeze integration (purge cache on start/end)
 * =======================================================*/

/** Settings: add a page under Settings → Scheduled Content. */
add_action( 'admin_menu', function () {
        add_options_page(
                __( 'Scheduled Content', 'scheduled-content-block' ),
                __( 'Scheduled Content', 'scheduled-content-block' ),
                'manage_options',
                'scblk-settings',
                'scblk_render_settings_page'
        );
});

add_action( 'admin_init', function () {
        register_setting( SCBLK_OPTION_GROUP, SCBLK_OPTION_VISIBILITY, array(
                'type'              => 'array',
                'sanitize_callback' => 'scblk_sanitize_visibility_roles',
                'default'           => scblk_visibility_default_roles(),
        ) );
        register_setting( SCBLK_OPTION_GROUP, SCBLK_OPTION_BREEZE_ENABLE, array(
                'type' => 'boolean',
                'sanitize_callback' => function( $v ) { return ( $v === '1' || $v === 1 || $v === true ) ? 1 : 0; },
                'default' => 0,
        ) );
        add_settings_section( 'scblk_main', '', '__return_false', 'scblk-settings' );
        add_settings_field(
                'scblk_visibility_roles',
                __( 'Roles allowed outside schedule', 'scheduled-content-block' ),
                'scblk_visibility_roles_field',
                'scblk-settings',
                'scblk_main'
        );
        if ( scblk_breeze_is_available() ) {
                add_settings_field(
                        'scblk_breeze_enable',
                        __( 'Purge Breeze cache at schedule boundaries', 'scheduled-content-block' ),
                        function () {
                                $enabled = (int) scblk_get_option( SCBLK_OPTION_BREEZE_ENABLE, 0 );
                                echo '<label><input type="checkbox" name="' . esc_attr( SCBLK_OPTION_BREEZE_ENABLE ) . '" value="1" ' . checked( 1, $enabled, false ) . ' />';
                                echo ' ' . esc_html__( 'Enable (purges site cache at each block’s start & end time).', 'scheduled-content-block' ) . '</label>';
                                echo '<p class="description">' . esc_html__( 'Requires the Breeze plugin. Uses Breeze’s purge-all hook.', 'scheduled-content-block' ) . '</p>';
                        },
                        'scblk-settings',
                        'scblk_main'
                );
        }
});

/** Settings page renderer */
function scblk_render_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Scheduled Content', 'scheduled-content-block' ); ?></h1>
		<form method="post" action="options.php">
			<?php
                        settings_fields( SCBLK_OPTION_GROUP );
                        do_settings_sections( 'scblk-settings' );
                        submit_button();
			?>
		</form>
		<?php if ( ! scblk_breeze_is_available() ) : ?>
			<p><em><?php esc_html_e( 'Breeze plugin is not active; purging will be skipped even if enabled.', 'scheduled-content-block' ); ?></em></p>
		<?php else : ?>
			<p><em><?php esc_html_e( 'Tip: Re-save posts that contain Scheduled Container blocks to (re)register purge times.', 'scheduled-content-block' ); ?></em></p>
		<?php endif; ?>
	</div>
	<?php
}

/** Default roles that can view content outside schedule. */
function scblk_visibility_default_roles() {
        return array_keys( wp_roles()->roles );
}

/** Sanitize roles option. */
function scblk_sanitize_visibility_roles( $roles ) {
        $valid = scblk_visibility_default_roles();
        $valid[] = 'visitor';
        if ( ! is_array( $roles ) ) return scblk_visibility_default_roles();
        $roles = array_map( 'sanitize_key', $roles );
        return array_values( array_intersect( $roles, $valid ) );
}

/** Settings field renderer for role visibility. */
function scblk_visibility_roles_field() {
        $value = scblk_get_option( SCBLK_OPTION_VISIBILITY, scblk_visibility_default_roles() );
        $roles = wp_roles()->role_names;
        foreach ( $roles as $slug => $name ) {
                echo '<label><input type="checkbox" name="' . esc_attr( SCBLK_OPTION_VISIBILITY ) . '[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $value, true ), true, false ) . ' /> ' . esc_html( $name ) . '</label><br />';
        }
        echo '<label><input type="checkbox" name="' . esc_attr( SCBLK_OPTION_VISIBILITY ) . '[]" value="visitor" ' . checked( in_array( 'visitor', $value, true ), true, false ) . ' /> ' . esc_html__( 'Visitors (not logged in)', 'scheduled-content-block' ) . '</label><br />';
        echo '<p class="description">' . esc_html__( 'Selected roles can view content outside scheduled times.', 'scheduled-content-block' ) . '</p>';
}

/** Check if current user is allowed to bypass schedule. */
function scblk_user_can_bypass_schedule() {
        $roles = scblk_get_option( SCBLK_OPTION_VISIBILITY, scblk_visibility_default_roles() );
        if ( ! is_array( $roles ) ) $roles = scblk_visibility_default_roles();
        if ( is_user_logged_in() ) {
                $user = wp_get_current_user();
                foreach ( $user->roles as $r ) {
                        if ( in_array( $r, $roles, true ) ) return true;
                }
        } else {
                if ( in_array( 'visitor', $roles, true ) ) return true;
        }
        return false;
}

/** Utility: is Breeze present (and offers the purge hook)? */
function scblk_breeze_is_available() {
	// Breeze exposes an action hook to purge all caches.
	return (bool) has_action( 'breeze_clear_all_cache' );
}

/** Utility: purge Breeze caches (site-wide). */
function scblk_breeze_purge_all() {
	// Trigger Breeze's purge-all hook (safe no-op if not hooked).
	// See: do_action('breeze_clear_all_cache') in Breeze docs/support.
	do_action( 'breeze_clear_all_cache' );
	// Optional: also try Varnish module if hooked.
	if ( has_action( 'breeze_clear_varnish' ) ) {
		do_action( 'breeze_clear_varnish' );
	}
}

/**
 * When a post is saved/updated, scan for our blocks and schedule purge events
 * at each future boundary (start/end). We store & clean up scheduled events
 * per post so updates don't leave stale cron jobs behind.
 */
add_action( 'save_post', function ( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) || 'trash' === $post->post_status ) return;

        // Only schedule if option enabled and Breeze available.
        if ( ! ( scblk_get_option( SCBLK_OPTION_BREEZE_ENABLE, 0 ) && scblk_breeze_is_available() ) ) {
                // Clean any events we previously scheduled for this post.
                scblk_breeze_unschedule_for_post( $post_id );
                return;
        }

	$content = $post->post_content;
	if ( empty( $content ) ) {
		scblk_breeze_unschedule_for_post( $post_id );
		return;
	}

	$blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : array();
	$boundaries = array(); // [ [ts, 'start'], [ts, 'end'], ... ]

	$now = time();

	$walk = function( $blocks ) use ( &$walk, &$boundaries, $now ) {
		foreach ( $blocks as $b ) {
			if ( empty( $b['blockName'] ) ) continue;
			if ( $b['blockName'] === 'h-b/scheduled-container' ) {
				$atts = isset( $b['attrs'] ) ? $b['attrs'] : array();
				if ( ! empty( $atts['start'] ) ) {
					$ts = scblk_parse_site_ts( $atts['start'] );
					if ( $ts && $ts > $now ) $boundaries[] = array( $ts, 'start' );
				}
				if ( ! empty( $atts['end'] ) ) {
					$ts = scblk_parse_site_ts( $atts['end'] );
					if ( $ts && $ts > $now ) $boundaries[] = array( $ts, 'end' );
				}
			}
			if ( ! empty( $b['innerBlocks'] ) && is_array( $b['innerBlocks'] ) ) {
				$walk( $b['innerBlocks'] );
			}
		}
	};

	$walk( $blocks );

	// Clear previously scheduled events for this post.
	scblk_breeze_unschedule_for_post( $post_id );

	// Schedule fresh ones.
	$scheduled = array();
        foreach ( $boundaries as $pair ) {
                list( $ts, $type ) = $pair;
                if ( ! wp_next_scheduled( SCBLK_CRON_HOOK, array( $post_id, $type, $ts ) ) ) {
                        wp_schedule_single_event( $ts, SCBLK_CRON_HOOK, array( $post_id, $type, $ts ) );
                        $scheduled[] = array( 'ts' => $ts, 'type' => $type );
                }
        }

        // Persist what we scheduled so we can unschedule on next edit.
        if ( ! empty( $scheduled ) ) {
                update_post_meta( $post_id, SCBLK_META_BREEZE_EVENTS, $scheduled );
        } else {
                delete_post_meta( $post_id, SCBLK_META_BREEZE_EVENTS );
        }
}, 10, 3 );

/** Unschedule previously registered events for a post (if any). */
function scblk_breeze_unschedule_for_post( $post_id ) {
        $events = scblk_get_breeze_events_meta( $post_id );
        if ( empty( $events ) || ! is_array( $events ) ) {
                delete_post_meta( $post_id, SCBLK_META_BREEZE_EVENTS );
                return;
        }
        foreach ( $events as $e ) {
                $ts   = isset( $e['ts'] ) ? (int) $e['ts'] : 0;
                $type = isset( $e['type'] ) ? (string) $e['type'] : '';
                if ( $ts > 0 && $type ) {
                        // Must match the args used when scheduling.
                        $next = wp_next_scheduled( SCBLK_CRON_HOOK, array( $post_id, $type, $ts ) );
                        if ( $next ) {
                                wp_unschedule_event( $next, SCBLK_CRON_HOOK, array( $post_id, $type, $ts ) );
                        }
                }
        }
        delete_post_meta( $post_id, SCBLK_META_BREEZE_EVENTS );
}

/** Cron callback: purge caches when a boundary is reached. */
function scblk_handle_breeze_cache_purge( $post_id, $type, $ts ) {
        // Double-check option and availability at runtime.
        if ( scblk_get_option( SCBLK_OPTION_BREEZE_ENABLE, 0 ) && scblk_breeze_is_available() ) {
                scblk_breeze_purge_all(); // Purge all Breeze caches.
        }
        // Clean the stored event (this exact entry).
        $events = scblk_get_breeze_events_meta( $post_id );
        if ( $events && is_array( $events ) ) {
                $events = array_values( array_filter( $events, function( $e ) use ( $ts, $type ) {
                        return ! ( isset( $e['ts'], $e['type'] ) && (int)$e['ts'] === (int)$ts && (string)$e['type'] === (string)$type );
                } ) );
                if ( $events ) update_post_meta( $post_id, SCBLK_META_BREEZE_EVENTS, $events );
                else delete_post_meta( $post_id, SCBLK_META_BREEZE_EVENTS );
        }
}
add_action( SCBLK_CRON_HOOK, 'scblk_handle_breeze_cache_purge', 10, 3 );

/** Clean up all scheduled events for this plugin on deactivation. */
register_deactivation_hook( __FILE__, function () {
	// Brute-force through all posts that might have meta.
        $q = new WP_Query( array(
                'post_type'      => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                        array( 'key' => SCBLK_META_BREEZE_EVENTS ),
                ),
        ) );
        if ( $q->have_posts() ) {
                foreach ( $q->posts as $pid ) {
                        scblk_breeze_unschedule_for_post( $pid );
                }
        }
});

/* =======================================================
 *  Jetpack CRM signature email mirroring (version 1.1.0)
 * =======================================================*/

/**
 * Bootstrap all hooks that integrate with Jetpack CRM. The hooks are harmless
 * if Jetpack CRM is not active, so we register them unconditionally.
 */
add_action( 'plugins_loaded', 'scblk_bootstrap_jpcrm_signature_mirror' );

/**
 * Local in-request storage for signature submission data.
 *
 * @param int|null $quote_id Quote identifier.
 * @param array|null $data    Data to merge/store for the quote.
 * @param bool $merge         Merge with existing payload (default true).
 *
 * @return array
 */
function scblk_signature_payload_store( $quote_id = null, $data = null, $merge = true ) {
        static $payloads = array();

        if ( null === $quote_id ) {
                return $payloads;
        }

        $quote_id = (int) $quote_id;
        if ( $quote_id <= 0 ) {
                return array();
        }

        if ( null === $data ) {
                return isset( $payloads[ $quote_id ] ) ? $payloads[ $quote_id ] : array();
        }

        if ( $merge && isset( $payloads[ $quote_id ] ) ) {
                $payloads[ $quote_id ] = array_merge( $payloads[ $quote_id ], $data );
        } else {
                $payloads[ $quote_id ] = $data;
        }

        return $payloads[ $quote_id ];
}

/** Mark a quote payload as already emailed to avoid duplicates. */
function scblk_signature_payload_mark_sent( $quote_id ) {
        $quote_id = (int) $quote_id;
        if ( $quote_id <= 0 ) {
                return;
        }

        $payload = scblk_signature_payload_store( $quote_id );
        $payload['_scblk_sent'] = true;
        scblk_signature_payload_store( $quote_id, $payload, false );
}

/** Determine whether we already mirrored the email for a quote. */
function scblk_signature_payload_was_sent( $quote_id ) {
        $payload = scblk_signature_payload_store( (int) $quote_id );
        return ! empty( $payload['_scblk_sent'] );
}

/**
 * Register hooks related to Jetpack CRM signature collection.
 */
function scblk_bootstrap_jpcrm_signature_mirror() {
        // Capture posted email + auxiliary data as early as possible.
        add_action( 'init', 'scblk_capture_signature_submission', 1 );

        // Inject the email field into the signature form (multiple hooks for compatibility).
        $field_filters = array(
                'jpcrm_signature_form_fields',
                'zeroBSCRM_signature_form_fields',
                'zbs_signature_form_fields',
        );
        foreach ( $field_filters as $filter ) {
                add_filter( $filter, 'scblk_signature_form_register_email_field', 10, 2 );
        }

        $field_actions = array(
                'jpcrm_signature_form_after_fields',
                'zeroBSCRM_signature_form_after_fields',
                'zbs_signature_form_after_fields',
        );
        foreach ( $field_actions as $action ) {
                add_action( $action, 'scblk_signature_form_render_email_field', 10, 0 );
        }

        // Mirror the admin email for clients across a broad set of signature hooks.
        scblk_register_signature_mirror_hooks();

        // Fallback in case none of the signature hooks fire (e.g. older Jetpack CRM).
        add_action( 'shutdown', 'scblk_signature_shutdown_fallback', 15 );
}

/**
 * Ensure the signature form knows about the email field. Jetpack CRM exposes
 * the signature fields via different filters depending on version; we try to
 * append our field when the filter is available.
 *
 * @param array $fields   Existing form fields.
 * @param int   $quote_id Quote identifier if provided by the filter.
 *
 * @return array
 */
function scblk_signature_form_register_email_field( $fields, $quote_id = 0 ) {
        if ( ! is_array( $fields ) ) {
                $fields = array();
        }

        $key = 'scblk_signature_email';
        if ( isset( $fields[ $key ] ) ) {
                return $fields;
        }

        $fields[ $key ] = array(
                'label'       => __( 'Email address', 'scheduled-content-block' ),
                'type'        => 'email',
                'required'    => true,
                'placeholder' => __( 'name@example.com', 'scheduled-content-block' ),
        );

        return $fields;
}

/**
 * Output the actual HTML for the email field so that sites without the above
 * filter (older Jetpack CRM versions) still see the field on the frontend.
 */
function scblk_signature_form_render_email_field() {
        static $rendered = false;
        if ( $rendered ) {
                return;
        }
        $rendered = true;

        $prefill = '';
        if ( isset( $_POST['scblk_signature_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $prefill = sanitize_email( wp_unslash( $_POST['scblk_signature_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        echo '<p class="scblk-signature-email-field">';
        echo '<label for="scblk_signature_email">' . esc_html__( 'Email address', 'scheduled-content-block' ) . ' <span class="required">*</span></label>';
        echo '<input type="email" name="scblk_signature_email" id="scblk_signature_email" value="' . esc_attr( $prefill ) . '" required />';
        echo '</p>';
}

/**
 * Capture the raw submission data before Jetpack CRM processes the signature.
 * We only store information required for emailing the client later.
 */
function scblk_capture_signature_submission() {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
                return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Jetpack CRM handles nonce validation.
        $source = $_POST;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( empty( $source ) || ! is_array( $source ) ) {
                return;
        }

        $quote_id = scblk_signature_extract_quote_id( $source );
        if ( ! $quote_id ) {
                return;
        }

        $email = scblk_signature_extract_email( $source );
        if ( ! $email ) {
                // Do not proceed if an email is not present; the client copy cannot be delivered.
                return;
        }

        $payload = array(
                'scblk_signature_email' => $email,
                'submitted_at'          => current_time( 'timestamp' ),
                'submitted_fields'      => scblk_signature_sanitise_fields( $source ),
        );

        $signature_blob = scblk_signature_extract_blob( $source );
        if ( $signature_blob ) {
                $payload['signature_raw'] = $signature_blob;
        }

        $submitted_time = scblk_signature_extract_signed_at( $source );
        if ( $submitted_time ) {
                $payload['signed_at'] = $submitted_time;
        }

        scblk_signature_payload_store( $quote_id, $payload );
}

/** Try to pull a quote ID out of a submission payload. */
function scblk_signature_extract_quote_id( array $source ) {
        $candidates = array(
                'quote_id', 'quoteID', 'jpcrm_quote_id', 'jpcrm_quoteid', 'jpcrm_quote',
                'zbsid', 'zbs_id', 'zbsquoteid', 'zbs_quote_id', 'zbs_qid', 'zbsqid',
                'zbscrm_quote_id', 'jpcrm_signature_quote', 'jpcrm_quote_ref', 'quoteid',
        );

        foreach ( $candidates as $key ) {
                if ( isset( $source[ $key ] ) ) {
                        $value = $source[ $key ];
                        if ( is_array( $value ) ) {
                                $value = reset( $value );
                        }
                        $quote_id = absint( $value );
                        if ( $quote_id > 0 ) {
                                return $quote_id;
                        }
                }
        }

        return 0;
}

/** Extract an email address from any array-like payload. */
function scblk_signature_extract_email( $source ) {
        if ( empty( $source ) ) {
                return '';
        }

        $keys = array(
                'scblk_signature_email', 'signature_email', 'client_email', 'email', 'signer_email', 'jpcrm_email',
        );

        foreach ( $keys as $key ) {
                if ( is_array( $source ) && isset( $source[ $key ] ) ) {
                        $value = $source[ $key ];
                        if ( is_array( $value ) ) {
                                $value = reset( $value );
                        }
                        $email = sanitize_email( wp_unslash( $value ) );
                        if ( $email ) {
                                return $email;
                        }
                }
        }

        return '';
}

/** Recursively sanitise posted fields for later reference. */
function scblk_signature_sanitise_fields( $source ) {
        $clean = array();
        foreach ( $source as $key => $value ) {
                $safe_key = sanitize_key( $key );
                if ( is_array( $value ) ) {
                        $clean[ $safe_key ] = scblk_signature_sanitise_fields( $value );
                } else {
                        $clean[ $safe_key ] = sanitize_text_field( wp_unslash( $value ) );
                }
        }
        return $clean;
}

/** Extract possible base64 signature blob from a submission. */
function scblk_signature_extract_blob( array $source ) {
        $keys = array( 'signature', 'signature_data', 'signaturedata', 'sig', 'sigData', 'signature_base64', 'jpcrm_signature' );
        foreach ( $keys as $key ) {
                if ( isset( $source[ $key ] ) ) {
                        $value = $source[ $key ];
                        if ( is_array( $value ) ) {
                                $value = reset( $value );
                        }
                        $value = trim( wp_unslash( $value ) );
                        if ( $value ) {
                                return $value;
                        }
                }
        }
        return '';
}

/** Extract a signing timestamp or date string from submission payload. */
function scblk_signature_extract_signed_at( array $source ) {
        $keys = array( 'signed_at', 'signed_at_gmt', 'sign_datetime', 'signing_datetime', 'signature_date', 'signature_time', 'signature_timestamp' );
        foreach ( $keys as $key ) {
                if ( isset( $source[ $key ] ) ) {
                        $value = $source[ $key ];
                        if ( is_array( $value ) ) {
                                $value = reset( $value );
                        }
                        $value = trim( wp_unslash( $value ) );
                        if ( $value !== '' ) {
                                return $value;
                        }
                }
        }
        return '';
}

/** Register multiple potential Jetpack CRM hooks for when a quote is signed. */
function scblk_register_signature_mirror_hooks() {
        $hooks = array(
                'jpcrm_quote_signed'           => 4,
                'jpcrm_after_quote_signed'     => 4,
                'jpcrm_quote_signature_saved'  => 4,
                'zeroBSCRM_quote_signed'       => 4,
                'zeroBSCRM_after_quote_signed' => 4,
                'zbs_quote_after_signature'    => 4,
        );

        foreach ( $hooks as $hook => $args ) {
                add_action( $hook, function() use ( $hook ) {
                        $params = func_get_args();
                        scblk_signature_maybe_send_copy( $hook, $params );
                }, 20, $args );
        }
}

/**
 * Attempt to dispatch the client copy of the signed quote email when one of
 * the Jetpack CRM hooks fires.
 *
 * @param string $hook  Hook name that triggered the call.
 * @param array  $args  Arguments passed by Jetpack CRM.
 */
function scblk_signature_maybe_send_copy( $hook, $args ) {
        if ( empty( $args ) ) {
                return;
        }

        $quote_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
        if ( ! $quote_id ) {
                return;
        }

        $payload = scblk_signature_payload_store( $quote_id );

        foreach ( $args as $arg ) {
                if ( is_array( $arg ) ) {
                        $payload = array_merge( $payload, $arg );
                }
        }

        scblk_signature_payload_store( $quote_id, $payload, false );

        if ( scblk_signature_payload_was_sent( $quote_id ) ) {
                return;
        }

        $email = scblk_signature_extract_email( $payload );
        if ( ! $email ) {
                return;
        }

        if ( scblk_signature_send_email_to_client( $quote_id, $email, $payload, $hook ) ) {
                scblk_signature_payload_mark_sent( $quote_id );
        }
}

/** Fallback sender executed late in the request. */
function scblk_signature_shutdown_fallback() {
        $all = scblk_signature_payload_store();
        if ( empty( $all ) ) {
                return;
        }

        foreach ( $all as $quote_id => $payload ) {
                $quote_id = (int) $quote_id;
                if ( $quote_id <= 0 || scblk_signature_payload_was_sent( $quote_id ) ) {
                        continue;
                }
                $email = scblk_signature_extract_email( $payload );
                if ( ! $email ) {
                        continue;
                }
                if ( scblk_signature_send_email_to_client( $quote_id, $email, $payload, 'shutdown' ) ) {
                        scblk_signature_payload_mark_sent( $quote_id );
                }
        }
}

/**
 * Build a canonical payload from Jetpack CRM / submission data and dispatch
 * the mirror email to the client.
 */
function scblk_signature_send_email_to_client( $quote_id, $email, array $payload, $context ) {
        $quote_id = (int) $quote_id;
        if ( $quote_id <= 0 ) {
                return false;
        }

        $email = sanitize_email( $email );
        if ( ! $email ) {
                return false;
        }

        $details = scblk_signature_prepare_email_details( $quote_id, $payload );
        if ( empty( $details ) ) {
                return false;
        }

        $subject = sprintf( __( 'Copy of signed quote #%d', 'scheduled-content-block' ), $details['quote_id'] );
        $body    = scblk_signature_render_email_body( $details );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $sent = wp_mail( $email, $subject, $body, $headers );

        if ( $sent ) {
                do_action( 'scblk_signature_client_email_sent', $quote_id, $email, $details, $context );
        }

        return $sent;
}

/**
 * Build a consolidated array of details for the outgoing client email.
 */
function scblk_signature_prepare_email_details( $quote_id, array $payload ) {
        $quote_id  = (int) $quote_id;
        $quote_url = get_permalink( $quote_id );
        if ( ! $quote_url ) {
                $quote_url = get_post_type_object( get_post_type( $quote_id ) ) ? get_permalink( $quote_id ) : '';
        }

        $signed_at = scblk_signature_resolve_signed_at( $payload );
        $signature = scblk_signature_resolve_signature_artifact( $quote_id, $payload );

        $submitted_fields = isset( $payload['submitted_fields'] ) && is_array( $payload['submitted_fields'] )
                ? $payload['submitted_fields']
                : array();

        return array(
                'quote_id'        => $quote_id,
                'quote_url'       => $quote_url,
                'signed_at'       => $signed_at,
                'signature_html'  => $signature['html'],
                'signature_text'  => $signature['text'],
                'signature_raw'   => $signature['raw'],
                'fields_snapshot' => $submitted_fields,
        );
}

/** Resolve a human-friendly signing timestamp. */
function scblk_signature_resolve_signed_at( array $payload ) {
        $candidates = array(
                'signed_at', 'signed_at_gmt', 'sign_datetime', 'signature_date', 'signature_time', 'signature_timestamp',
        );

        $value = '';
        foreach ( $candidates as $key ) {
                if ( ! empty( $payload[ $key ] ) ) {
                        $candidate = $payload[ $key ];
                        if ( is_array( $candidate ) ) {
                                $candidate = reset( $candidate );
                        }
                        $value = trim( (string) $candidate );
                        if ( '' !== $value ) {
                                break;
                        }
                }
        }

        if ( '' === $value && isset( $payload['submitted_at'] ) ) {
                $value = $payload['submitted_at'];
        }

        if ( '' === $value ) {
                $value = current_time( 'timestamp' );
        }

        if ( is_numeric( $value ) ) {
                $timestamp = (int) $value;
        } else {
                $timestamp = strtotime( $value );
        }

        if ( ! $timestamp ) {
                $timestamp = current_time( 'timestamp' );
        }

        return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}

/**
 * Attempt to resolve the signature artifact as HTML/text/raw for the email.
 */
function scblk_signature_resolve_signature_artifact( $quote_id, array $payload ) {
        $raw = '';
        if ( ! empty( $payload['signature_raw'] ) && is_string( $payload['signature_raw'] ) ) {
                $raw = $payload['signature_raw'];
        }

        // Look through payload arrays for signature details.
        $keys = array( 'signature', 'signature_data', 'signature_html', 'signature_text', 'signature_image' );
        foreach ( $keys as $key ) {
                if ( isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) && '' === $raw ) {
                        $raw = $payload[ $key ];
                        break;
                }
        }

        // Fall back to post meta if Jetpack CRM stored the signature there.
        if ( '' === $raw ) {
                $meta = get_post_meta( $quote_id );
                if ( $meta && is_array( $meta ) ) {
                        foreach ( $meta as $key => $values ) {
                                if ( false === stripos( $key, 'signature' ) ) {
                                        continue;
                                }
                                $value = is_array( $values ) ? end( $values ) : $values;
                                if ( is_string( $value ) && $value !== '' ) {
                                        $raw = $value;
                                        break;
                                }
                        }
                }
        }

        $html = '';
        $text = '';

        if ( $raw && strpos( $raw, '<svg' ) !== false ) {
                $html = $raw;
        } elseif ( $raw && 0 === strpos( $raw, 'data:image' ) ) {
                $html = '<img alt="' . esc_attr__( 'Signature', 'scheduled-content-block' ) . '" src="' . esc_url( $raw ) . '" style="max-width:480px;height:auto;border:1px solid #ddd;" />';
        } elseif ( $raw && false !== strpos( $raw, 'base64,' ) ) {
                $pos      = strpos( $raw, 'base64,' );
                $prefix   = substr( $raw, 0, $pos + 7 );
                $img_data = trim( substr( $raw, $pos + 7 ) );
                if ( stripos( $prefix, 'data:' ) !== 0 ) {
                        $prefix = 'data:image/png;base64,';
                }
                $html = '<img alt="' . esc_attr__( 'Signature', 'scheduled-content-block' ) . '" src="' . esc_url( $prefix . $img_data ) . '" style="max-width:480px;height:auto;border:1px solid #ddd;" />';
        } elseif ( $raw ) {
                $text = $raw;
        }

        return array(
                'raw'  => $raw,
                'html' => $html,
                'text' => $text,
        );
}

/** Render the HTML body for the outgoing client email. */
function scblk_signature_render_email_body( array $details ) {
        $quote_url = $details['quote_url'] ? esc_url( $details['quote_url'] ) : '';

        $body  = '<p>' . esc_html__( 'Thank you for signing your quote. Please find a copy of the details below.', 'scheduled-content-block' ) . '</p>';
        $body .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 16px 0;">';
        $body .= '<tr><th align="left" style="padding:4px 12px 4px 0;">' . esc_html__( 'Quote ID:', 'scheduled-content-block' ) . '</th><td style="padding:4px 0;">' . esc_html( $details['quote_id'] ) . '</td></tr>';
        $body .= '<tr><th align="left" style="padding:4px 12px 4px 0;">' . esc_html__( 'Quote URL:', 'scheduled-content-block' ) . '</th><td style="padding:4px 0;">';
        if ( $quote_url ) {
                $body .= '<a href="' . $quote_url . '">' . $quote_url . '</a>';
        } else {
                $body .= esc_html__( 'Not available', 'scheduled-content-block' );
        }
        $body .= '</td></tr>';
        $body .= '<tr><th align="left" style="padding:4px 12px 4px 0;">' . esc_html__( 'Signed on:', 'scheduled-content-block' ) . '</th><td style="padding:4px 0;">' . esc_html( $details['signed_at'] ) . '</td></tr>';
        $body .= '</table>';

        if ( ! empty( $details['signature_html'] ) ) {
                $body .= '<p><strong>' . esc_html__( 'Signature', 'scheduled-content-block' ) . '</strong><br />' . $details['signature_html'] . '</p>';
        } elseif ( ! empty( $details['signature_text'] ) ) {
                $body .= '<p><strong>' . esc_html__( 'Signature', 'scheduled-content-block' ) . ':</strong> ' . esc_html( $details['signature_text'] ) . '</p>';
        }

        if ( ! empty( $details['fields_snapshot'] ) ) {
                $body .= '<hr />';
                $body .= '<p><strong>' . esc_html__( 'Submission details', 'scheduled-content-block' ) . '</strong></p>';
                $body .= '<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;overflow:auto;">' . esc_html( scblk_signature_format_snapshot( $details['fields_snapshot'] ) ) . '</pre>';
        }

        if ( empty( $details['signature_html'] ) && ! empty( $details['signature_raw'] ) ) {
                $body .= '<hr />';
                $body .= '<p><strong>' . esc_html__( 'Signature data (raw)', 'scheduled-content-block' ) . '</strong></p>';
                $body .= '<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;overflow:auto;">' . esc_html( $details['signature_raw'] ) . '</pre>';
        }

        $body .= '<p>' . esc_html__( 'If you have any questions, please reply to this email.', 'scheduled-content-block' ) . '</p>';

        return $body;
}

/** Flatten the submitted fields snapshot into a human-readable string. */
function scblk_signature_format_snapshot( array $fields, $prefix = '' ) {
        $lines = array();
        foreach ( $fields as $key => $value ) {
                $label = $prefix ? $prefix . $key : $key;
                if ( is_array( $value ) ) {
                        $lines[] = scblk_signature_format_snapshot( $value, $label . '.' );
                } else {
                        $lines[] = $label . ': ' . $value;
                }
        }
        return implode( "\n", array_filter( $lines ) );
}
