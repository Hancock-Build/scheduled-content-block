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
define( 'SCBLK_META_DELETE_EVENTS', '_scblk_delete_events' );
define( 'SCBLK_DELETE_CRON_HOOK', 'scblk_delete_expired_blocks' );

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
 * Fetch scheduled delete events stored in post meta.
 */
function scblk_get_delete_events_meta( $post_id ) {
        $events = get_post_meta( $post_id, SCBLK_META_DELETE_EVENTS, true );
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
                'deleteAfterEnd'   => false,
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
 *  Optional block deletion after schedule end
 * =======================================================*/
function scblk_remove_expired_blocks( $blocks, $now, &$changed ) {
        $filtered = array();
        foreach ( $blocks as $block ) {
                if ( empty( $block['blockName'] ) ) {
                        $filtered[] = $block;
                        continue;
                }
                if ( $block['blockName'] === 'h-b/scheduled-container' ) {
                        $atts = isset( $block['attrs'] ) ? $block['attrs'] : array();
                        if ( ! empty( $atts['deleteAfterEnd'] ) && ! empty( $atts['end'] ) ) {
                                $end_ts = scblk_parse_site_ts( $atts['end'] );
                                if ( $end_ts !== null && $end_ts <= $now ) {
                                        $changed = true;
                                        continue;
                                }
                        }
                }
                if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                        $inner_changed = false;
                        $block['innerBlocks'] = scblk_remove_expired_blocks( $block['innerBlocks'], $now, $inner_changed );
                        if ( $inner_changed ) {
                                $changed = true;
                        }
                }
                $filtered[] = $block;
        }
        return $filtered;
}

function scblk_remove_expired_blocks_from_content( $content, $now ) {
        if ( empty( $content ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
                return array( $content, false );
        }
        $blocks = parse_blocks( $content );
        $changed = false;
        $filtered = scblk_remove_expired_blocks( $blocks, $now, $changed );
        if ( ! $changed ) {
                return array( $content, false );
        }
        return array( serialize_blocks( $filtered ), true );
}

function scblk_schedule_delete_events( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) || 'trash' === $post->post_status ) return;

        $content = $post->post_content;
        if ( empty( $content ) ) {
                scblk_delete_unschedule_for_post( $post_id );
                return;
        }

        if ( ! function_exists( 'parse_blocks' ) ) {
                return;
        }

        $blocks = parse_blocks( $content );
        $boundaries = array();
        $now = time();
        $has_expired = false;

        $walk = function( $blocks ) use ( &$walk, &$boundaries, $now, &$has_expired ) {
                foreach ( $blocks as $b ) {
                        if ( empty( $b['blockName'] ) ) continue;
                        if ( $b['blockName'] === 'h-b/scheduled-container' ) {
                                $atts = isset( $b['attrs'] ) ? $b['attrs'] : array();
                                if ( ! empty( $atts['deleteAfterEnd'] ) && ! empty( $atts['end'] ) ) {
                                        $ts = scblk_parse_site_ts( $atts['end'] );
                                        if ( $ts !== null ) {
                                                if ( $ts > $now ) {
                                                        $boundaries[] = $ts;
                                                } else {
                                                        $has_expired = true;
                                                }
                                        }
                                }
                        }
                        if ( ! empty( $b['innerBlocks'] ) && is_array( $b['innerBlocks'] ) ) {
                                $walk( $b['innerBlocks'] );
                        }
                }
        };

        $walk( $blocks );

        if ( $has_expired && function_exists( 'serialize_blocks' ) ) {
                $changed = false;
                $filtered = scblk_remove_expired_blocks( $blocks, $now, $changed );
                if ( $changed ) {
                        remove_action( 'save_post', 'scblk_schedule_delete_events', 10 );
                        wp_update_post( array(
                                'ID' => $post_id,
                                'post_content' => serialize_blocks( $filtered ),
                        ) );
                        add_action( 'save_post', 'scblk_schedule_delete_events', 10, 3 );
                }
        }

        scblk_delete_unschedule_for_post( $post_id );

        $scheduled = array();
        foreach ( $boundaries as $ts ) {
                if ( ! wp_next_scheduled( SCBLK_DELETE_CRON_HOOK, array( $post_id, $ts ) ) ) {
                        wp_schedule_single_event( $ts, SCBLK_DELETE_CRON_HOOK, array( $post_id, $ts ) );
                        $scheduled[] = array( 'ts' => $ts );
                }
        }

        if ( ! empty( $scheduled ) ) {
                update_post_meta( $post_id, SCBLK_META_DELETE_EVENTS, $scheduled );
        } else {
                delete_post_meta( $post_id, SCBLK_META_DELETE_EVENTS );
        }
}
add_action( 'save_post', 'scblk_schedule_delete_events', 10, 3 );

function scblk_delete_unschedule_for_post( $post_id ) {
        $events = scblk_get_delete_events_meta( $post_id );
        if ( empty( $events ) || ! is_array( $events ) ) {
                delete_post_meta( $post_id, SCBLK_META_DELETE_EVENTS );
                return;
        }
        foreach ( $events as $e ) {
                $ts = isset( $e['ts'] ) ? (int) $e['ts'] : 0;
                if ( $ts > 0 ) {
                        $next = wp_next_scheduled( SCBLK_DELETE_CRON_HOOK, array( $post_id, $ts ) );
                        if ( $next ) {
                                wp_unschedule_event( $next, SCBLK_DELETE_CRON_HOOK, array( $post_id, $ts ) );
                        }
                }
        }
        delete_post_meta( $post_id, SCBLK_META_DELETE_EVENTS );
}

function scblk_handle_delete_expired_blocks( $post_id, $ts ) {
        $post = get_post( $post_id );
        if ( ! $post || empty( $post->post_content ) ) {
                return;
        }
        list( $content, $changed ) = scblk_remove_expired_blocks_from_content( $post->post_content, time() );
        if ( $changed ) {
                remove_action( 'save_post', 'scblk_schedule_delete_events', 10 );
                wp_update_post( array(
                        'ID' => $post_id,
                        'post_content' => $content,
                ) );
                add_action( 'save_post', 'scblk_schedule_delete_events', 10, 3 );
        }

        $events = scblk_get_delete_events_meta( $post_id );
        if ( $events && is_array( $events ) ) {
                $events = array_values( array_filter( $events, function( $e ) use ( $ts ) {
                        return ! ( isset( $e['ts'] ) && (int) $e['ts'] === (int) $ts );
                } ) );
                if ( $events ) update_post_meta( $post_id, SCBLK_META_DELETE_EVENTS, $events );
                else delete_post_meta( $post_id, SCBLK_META_DELETE_EVENTS );
        }
}
add_action( SCBLK_DELETE_CRON_HOOK, 'scblk_handle_delete_expired_blocks', 10, 2 );

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
                <style>
                        .scblk-settings-card {
                                max-width: 820px;
                                background: #fff;
                                border: 1px solid #e0e0e0;
                                border-radius: 8px;
                                padding: 20px 24px;
                                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
                                margin-top: 16px;
                        }
                        .scblk-settings-card h1 {
                                margin-top: 0;
                        }
                        .scblk-settings-card .form-table th {
                                width: 260px;
                        }
                        .scblk-settings-card .description {
                                margin-top: 6px;
                        }
                        .scblk-settings-footer {
                                margin-top: 16px;
                        }
                </style>
                <div class="scblk-settings-card">
		<h1><?php esc_html_e( 'Scheduled Content', 'scheduled-content-block' ); ?></h1>
		<form method="post" action="options.php">
			<?php
                        settings_fields( SCBLK_OPTION_GROUP );
                        do_settings_sections( 'scblk-settings' );
                        submit_button();
			?>
		</form>
                        <div class="scblk-settings-footer">
                                <?php if ( ! scblk_breeze_is_available() ) : ?>
                                        <p><em><?php esc_html_e( 'Breeze plugin is not active; purging will be skipped even if enabled.', 'scheduled-content-block' ); ?></em></p>
                                <?php else : ?>
                                        <p><em><?php esc_html_e( 'Tip: Re-save posts that contain Scheduled Container blocks to (re)register purge times.', 'scheduled-content-block' ); ?></em></p>
                                <?php endif; ?>
                        </div>
                </div>
	</div>
	<?php
}

/** Default roles that can view content outside schedule. */
function scblk_visibility_default_roles() {
        $roles = wp_roles();
        if ( isset( $roles->roles['administrator'] ) ) {
                return array( 'administrator' );
        }
        return array_keys( $roles->roles );
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
                        'relation' => 'OR',
                        array( 'key' => SCBLK_META_BREEZE_EVENTS ),
                        array( 'key' => SCBLK_META_DELETE_EVENTS ),
                ),
        ) );
        if ( $q->have_posts() ) {
                foreach ( $q->posts as $pid ) {
                        scblk_breeze_unschedule_for_post( $pid );
                        scblk_delete_unschedule_for_post( $pid );
                }
        }
});
