<?php
/**
 * Plugin Name: Scheduled Content Block
 * Description: A simple container block that enables the easy scheduleing of content on WordPress pages or posts.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Author: h.b Plugins
 * Author URI: https://hancock.build
 * License: GPL-3.0-or-later
 * Text Domain: scheduled-content-block
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SCB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register the block (metadata) and attach server render callback.
 */
add_action( 'init', function() {
	register_block_type( __DIR__ . '/block', array(
		'render_callback' => 'scb_render_callback',
	) );
} );

/**
 * Inline the editor script to avoid HTTP fetch issues (e.g. WAF/404 serving HTML).
 */
add_action('enqueue_block_editor_assets', function () {
	$deps = array('wp-blocks','wp-element','wp-components','wp-editor','wp-i18n','wp-block-editor');
	wp_register_script('scb-inline-editor', false, $deps, '1.2.0', true);
	wp_enqueue_script('scb-inline-editor');

	$path = SCB_PLUGIN_DIR . 'block/editor.js';
	$js = @file_get_contents($path);

	if ($js === false) {
		$js = "(function(wp){var el=wp.element.createElement,be=wp.blockEditor||wp.editor;var Inner=be.InnerBlocks;wp.blocks.registerBlockType('h-b/scheduled-container',{edit:function(){return el('div',null,'Scheduled Container (inline fallback)');},save:function(){return el(Inner.Content,null);}});})(window.wp);";
	}

	wp_add_inline_script('scb-inline-editor', $js);
});

/* -------------------------------------------------------
 * Core render logic (unchanged)
 * -----------------------------------------------------*/
function scb_render_callback( $attributes, $content, $block ) {
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
			return scb_wrap_editor_notice( $atts, $content );
		}
	}

        // Allow bypass based on selected roles.
        if ( scb_user_can_bypass_schedule() ) {
                return $content;
        }

	$now     = scb_now_site_ts();
	$startTs = scb_parse_site_ts( $atts['start'] );
	$endTs   = scb_parse_site_ts( $atts['end'] );

	$hasStart = ( $atts['start'] !== '' && $startTs !== null );
	$hasEnd   = ( $atts['end']   !== '' && $endTs   !== null );

	// If user set a value but it failed to parse, hide (safe).
	$invalid = ( $atts['start'] !== '' && $startTs === null ) || ( $atts['end'] !== '' && $endTs === null );
	if ( $invalid ) {
		return ! empty( $atts['showPlaceholder'] ) ? '<div class="scb-placeholder" aria-hidden="true">' . esc_html( (string) $atts['placeholderText'] ) . '</div>' : '';
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
	return ! empty( $atts['showPlaceholder'] ) ? '<div class="scb-placeholder" aria-hidden="true">' . esc_html( (string) $atts['placeholderText'] ) . '</div>' : '';
}

/**
 * Parse an ISO date string:
 *  - with a timezone (Z or ±HH:MM): honor as absolute moment;
 *  - without timezone: interpret in site timezone.
 */
function scb_parse_site_ts( $iso ) {
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
function scb_now_site_ts() { return time(); }

/** Editor-only wrapper with a visible schedule badge */
function scb_wrap_editor_notice( $atts, $content ) {
	$badge = scb_schedule_badge_html( $atts, true );
	return '<div class="scb-editor-wrap">' . $badge . $content . '</div>';
}

/** Render a small schedule badge (editor uses friendly format) */
function scb_schedule_badge_html( $atts, $is_editor ) {
	$tz   = wp_timezone_string() ?: 'UTC';
	$fmt  = $is_editor ? 'F j Y \a\t g:ia' : ( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

	$start_ts = scb_parse_site_ts( $atts['start'] );
	$end_ts   = scb_parse_site_ts( $atts['end'] );

        $start = ($atts['start'] && $start_ts !== null) ? wp_date( $fmt, $start_ts ) : '—';
        $end   = ($atts['end'] && $end_ts   !== null)   ? wp_date( $fmt, $end_ts )   : '—';
        $context = $is_editor ? 'Editor preview' : 'Frontend view';

        return sprintf(
                '<div class="scb-badge"><strong>Scheduled Content:</strong> %s | <strong>Start:</strong> %s | <strong>End:</strong> %s | <strong>TZ:</strong> %s</div>',
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
                'scb-settings',
                'scb_render_settings_page'
        );
});

add_action( 'admin_init', function () {
        register_setting( 'scb_settings', 'scb_visibility_roles', array(
                'type'              => 'array',
                'sanitize_callback' => 'scb_sanitize_visibility_roles',
                'default'           => scb_visibility_default_roles(),
        ) );
        register_setting( 'scb_settings', 'scb_breeze_enable', array(
                'type' => 'boolean',
                'sanitize_callback' => function( $v ) { return ( $v === '1' || $v === 1 || $v === true ) ? 1 : 0; },
                'default' => 0,
        ) );
        add_settings_section( 'scb_main', '', '__return_false', 'scb-settings' );
        add_settings_field(
                'scb_visibility_roles',
                __( 'Roles allowed outside schedule', 'scheduled-content-block' ),
                'scb_visibility_roles_field',
                'scb-settings',
                'scb_main'
        );
        if ( scb_breeze_is_available() ) {
                add_settings_field(
                        'scb_breeze_enable',
                        __( 'Purge Breeze cache at schedule boundaries', 'scheduled-content-block' ),
                        function () {
                                $enabled = (int) get_option( 'scb_breeze_enable', 0 );
                                echo '<label><input type="checkbox" name="scb_breeze_enable" value="1" ' . checked( 1, $enabled, false ) . ' />';
                                echo ' ' . esc_html__( 'Enable (purges site cache at each block’s start & end time).', 'scheduled-content-block' ) . '</label>';
                                echo '<p class="description">' . esc_html__( 'Requires the Breeze plugin. Uses Breeze’s purge-all hook.', 'scheduled-content-block' ) . '</p>';
                        },
                        'scb-settings',
                        'scb_main'
                );
        }
});

/** Settings page renderer */
function scb_render_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Scheduled Content', 'scheduled-content-block' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'scb_settings' );
			do_settings_sections( 'scb-settings' );
			submit_button();
			?>
		</form>
		<?php if ( ! scb_breeze_is_available() ) : ?>
			<p><em><?php esc_html_e( 'Breeze plugin is not active; purging will be skipped even if enabled.', 'scheduled-content-block' ); ?></em></p>
		<?php else : ?>
			<p><em><?php esc_html_e( 'Tip: Re-save posts that contain Scheduled Container blocks to (re)register purge times.', 'scheduled-content-block' ); ?></em></p>
		<?php endif; ?>
	</div>
	<?php
}

/** Default roles that can view content outside schedule. */
function scb_visibility_default_roles() {
        return array_keys( wp_roles()->roles );
}

/** Sanitize roles option. */
function scb_sanitize_visibility_roles( $roles ) {
        $valid = scb_visibility_default_roles();
        $valid[] = 'visitor';
        if ( ! is_array( $roles ) ) return scb_visibility_default_roles();
        $roles = array_map( 'sanitize_key', $roles );
        return array_values( array_intersect( $roles, $valid ) );
}

/** Settings field renderer for role visibility. */
function scb_visibility_roles_field() {
        $value = get_option( 'scb_visibility_roles', scb_visibility_default_roles() );
        $roles = wp_roles()->role_names;
        foreach ( $roles as $slug => $name ) {
                echo '<label><input type="checkbox" name="scb_visibility_roles[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $value, true ), true, false ) . ' /> ' . esc_html( $name ) . '</label><br />';
        }
        echo '<label><input type="checkbox" name="scb_visibility_roles[]" value="visitor" ' . checked( in_array( 'visitor', $value, true ), true, false ) . ' /> ' . esc_html__( 'Visitors (not logged in)', 'scheduled-content-block' ) . '</label><br />';
        echo '<p class="description">' . esc_html__( 'Selected roles can view content outside scheduled times.', 'scheduled-content-block' ) . '</p>';
}

/** Check if current user is allowed to bypass schedule. */
function scb_user_can_bypass_schedule() {
        $roles = get_option( 'scb_visibility_roles', scb_visibility_default_roles() );
        if ( ! is_array( $roles ) ) $roles = scb_visibility_default_roles();
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
function scb_breeze_is_available() {
	// Breeze exposes an action hook to purge all caches.
	return (bool) has_action( 'breeze_clear_all_cache' );
}

/** Utility: purge Breeze caches (site-wide). */
function scb_breeze_purge_all() {
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
	if ( ! ( get_option( 'scb_breeze_enable', 0 ) && scb_breeze_is_available() ) ) {
		// Clean any events we previously scheduled for this post.
		scb_breeze_unschedule_for_post( $post_id );
		return;
	}

	$content = $post->post_content;
	if ( empty( $content ) ) {
		scb_breeze_unschedule_for_post( $post_id );
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
					$ts = scb_parse_site_ts( $atts['start'] );
					if ( $ts && $ts > $now ) $boundaries[] = array( $ts, 'start' );
				}
				if ( ! empty( $atts['end'] ) ) {
					$ts = scb_parse_site_ts( $atts['end'] );
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
	scb_breeze_unschedule_for_post( $post_id );

	// Schedule fresh ones.
	$scheduled = array();
	foreach ( $boundaries as $pair ) {
		list( $ts, $type ) = $pair;
		if ( ! wp_next_scheduled( 'scb_breeze_cache_purge', array( $post_id, $type, $ts ) ) ) {
			wp_schedule_single_event( $ts, 'scb_breeze_cache_purge', array( $post_id, $type, $ts ) );
			$scheduled[] = array( 'ts' => $ts, 'type' => $type );
		}
	}

	// Persist what we scheduled so we can unschedule on next edit.
	if ( ! empty( $scheduled ) ) {
		update_post_meta( $post_id, '_scb_breeze_events', $scheduled );
	} else {
		delete_post_meta( $post_id, '_scb_breeze_events' );
	}
}, 10, 3 );

/** Unschedule previously registered events for a post (if any). */
function scb_breeze_unschedule_for_post( $post_id ) {
	$events = get_post_meta( $post_id, '_scb_breeze_events', true );
	if ( empty( $events ) || ! is_array( $events ) ) return;
	foreach ( $events as $e ) {
		$ts   = isset( $e['ts'] ) ? (int) $e['ts'] : 0;
		$type = isset( $e['type'] ) ? (string) $e['type'] : '';
		if ( $ts > 0 && $type ) {
			// Must match the args used when scheduling:
			$next = wp_next_scheduled( 'scb_breeze_cache_purge', array( $post_id, $type, $ts ) );
			if ( $next ) {
				wp_unschedule_event( $next, 'scb_breeze_cache_purge', array( $post_id, $type, $ts ) );
			}
		}
	}
	delete_post_meta( $post_id, '_scb_breeze_events' );
}

/** Cron callback: purge caches when a boundary is reached. */
add_action( 'scb_breeze_cache_purge', function ( $post_id, $type, $ts ) {
	// Double-check option and availability at runtime.
	if ( get_option( 'scb_breeze_enable', 0 ) && scb_breeze_is_available() ) {
		scb_breeze_purge_all(); // Purge all Breeze caches.
	}
	// Clean the stored event (this exact entry).
	$events = get_post_meta( $post_id, '_scb_breeze_events', true );
	if ( $events && is_array( $events ) ) {
		$events = array_values( array_filter( $events, function( $e ) use ( $ts, $type ) {
			return ! ( isset( $e['ts'], $e['type'] ) && (int)$e['ts'] === (int)$ts && (string)$e['type'] === (string)$type );
		} ) );
		if ( $events ) update_post_meta( $post_id, '_scb_breeze_events', $events );
		else delete_post_meta( $post_id, '_scb_breeze_events' );
	}
}, 10, 3 );

/** Clean up all scheduled events for this plugin on deactivation. */
register_deactivation_hook( __FILE__, function () {
	// Brute-force through all posts that might have meta.
	$q = new WP_Query( array(
		'post_type'      => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_scb_breeze_events',
	) );
	if ( $q->have_posts() ) {
		foreach ( $q->posts as $pid ) {
			scb_breeze_unschedule_for_post( $pid );
		}
	}
});
