<?php
/** Run against a disposable WordPress installation with this plugin active. */
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 4 ) ) . '/wp-load.php';

$checks = 0;
function scblk_test( $condition, $message ) {
        global $checks;
        if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $message );
        ++$checks;
}

$saved = array();
foreach ( array( 'timezone_string', 'gmt_offset', SCBLK_OPTION_VISIBILITY, SCBLK_OPTION_BREEZE_ENABLE ) as $option ) {
        $saved[ $option ] = get_option( $option, null );
}
$post_id = 0;
$purges = 0;
$purge = function () use ( &$purges ) { ++$purges; };
add_action( 'breeze_clear_all_cache', $purge );
try {
        wp_set_current_user( 0 );
        update_option( SCBLK_OPTION_VISIBILITY, array() );
        $cases = json_decode( file_get_contents( __DIR__ . '/schedule-cases.json' ), true, 512, JSON_THROW_ON_ERROR );
        foreach ( $cases as $case ) {
                if ( preg_match( '/^([+-])(\d{2}):(\d{2})$/', $case['timezone'], $offset ) ) {
                        update_option( 'timezone_string', '' );
                        update_option( 'gmt_offset', ( '-' === $offset[1] ? -1 : 1 ) * ( (int) $offset[2] + (int) $offset[3] / 60 ) );
                } else {
                        update_option( 'timezone_string', $case['timezone'] );
                }
                $now = strtotime( $case['at'] );
                $state = scblk_schedule_state( $case['attributes'], $now );
                scblk_test( $state['status'] === $case['expected'], $case['name'] . ': expected ' . $case['expected'] . ', got ' . $state['status'] );
                foreach ( $state['boundaries'] as $boundary ) {
                        scblk_test( $boundary[0] > $now, $case['name'] . ': cache boundary must be in the future' );
                }
        }

        update_option( 'timezone_string', 'UTC' );
        $atts = array( 'start' => '2026-01-31T09:00:00', 'end' => '2026-01-31T17:00:00', 'recurrence' => 'monthly' );
        $state = scblk_schedule_state( $atts, strtotime( '2026-02-28T17:00:00Z' ) );
        scblk_test( $state['boundaries'] === array( array( strtotime( '2026-03-31T09:00:00Z' ), 'start' ), array( strtotime( '2026-02-28T17:00:01Z' ), 'end' ) ), 'month-end cache boundaries and inclusive end' );

        $now = time();
        $atts = array( 'start' => gmdate( 'c', $now - 2 * DAY_IN_SECONDS - 600 ), 'end' => gmdate( 'c', $now - 2 * DAY_IN_SECONDS + 600 ), 'recurrence' => 'daily', 'deleteAfterEnd' => true );
        $marker = '<p>RECURRING_CONTENT</p>';
        $content = '<!-- wp:h-b/scheduled-container ' . wp_json_encode( $atts ) . ' -->' . $marker . '<!-- /wp:h-b/scheduled-container -->';
        scblk_test( scblk_render_callback( $atts, $marker, null ) === $marker, 'frontend renders a later occurrence' );
        scblk_test( str_contains( do_blocks( $content ), 'RECURRING_CONTENT' ), 'block attributes survive WordPress registration and rendering' );
        $gap = array_merge( $atts, array( 'end' => gmdate( 'c', $now - 2 * DAY_IN_SECONDS - 300 ), 'showPlaceholder' => true, 'placeholderText' => '<waiting>' ) );
        scblk_test( scblk_render_callback( $gap, $marker, null ) === '<div class="scblk-placeholder" aria-hidden="true">&lt;waiting&gt;</div>', 'recurring gap escapes placeholder' );
        scblk_test( scblk_render_callback( array_merge( $atts, array( 'recurrenceInterval' => 0 ) ), $marker, null ) === '', 'invalid recurrence fails closed' );
        $fraction = str_replace( '"recurrence":"daily"', '"recurrence":"daily","recurrenceInterval":1.5', $content );
        scblk_test( ! str_contains( do_blocks( $fraction ), 'RECURRING_CONTENT' ), 'fractional interval stays invalid through WordPress attribute validation' );
        update_option( SCBLK_OPTION_VISIBILITY, array( 'visitor' ) );
        scblk_test( scblk_render_callback( $gap, $marker, null ) === $marker, 'visitor bypass still applies to recurrence' );
        update_option( SCBLK_OPTION_VISIBILITY, array() );

        // Recurrence must survive both save-time deletion and an old one-off cron job.
        scblk_test( scblk_remove_expired_blocks_from_content( $content, $now ) === array( $content, false ), 'recurring block survives expired original end' );
        update_option( SCBLK_OPTION_BREEZE_ENABLE, 1 );
        $post_id = wp_insert_post( array( 'post_title' => 'Recurrence regression test', 'post_status' => 'draft', 'post_content' => wp_slash( $content ) ), true );
        scblk_test( ! is_wp_error( $post_id ), 'test post saved' );
        scblk_test( ! scblk_get_delete_events_meta( $post_id ), 'no deletion cron for recurrence' );
        scblk_handle_delete_expired_blocks( $post_id, scblk_parse_site_ts( $atts['end'] ) );
        scblk_test( get_post( $post_id )->post_content === $content, 'stale deletion job preserves recurrence' );
        $events = scblk_get_breeze_events_meta( $post_id );
        scblk_test( count( $events ) === 2, 'next recurring start and end are scheduled' );
        foreach ( $events as $event ) {
                scblk_test( wp_next_scheduled( SCBLK_CRON_HOOK, array( $post_id, $event['type'], $event['ts'] ) ) === $event['ts'], 'cache event exists in WP-Cron' );
        }
        scblk_handle_breeze_cache_purge( $post_id, 'end', scblk_parse_site_ts( $atts['end'] ) + 1 );
        scblk_test( $purges === 1, 'delayed boundary purges Breeze' );
        $events = scblk_get_breeze_events_meta( $post_id );
        scblk_test( count( $events ) === 2 && min( array_column( $events, 'ts' ) ) > $now, 'delayed cron re-arms future occurrences without duplicates' );

        // A repeating child and its sibling survive removal of an expired one-off child.
        $expired = '<!-- wp:h-b/scheduled-container ' . wp_json_encode( array( 'end' => gmdate( 'c', $now - 100 ), 'deleteAfterEnd' => true ) ) . ' --><p>EXPIRED</p><!-- /wp:h-b/scheduled-container -->';
        $nested = '<!-- wp:group --><div class="wp-block-group">' . $expired . $content . '<!-- wp:paragraph --><p>SIBLING</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
        list( $remaining, $changed ) = scblk_remove_expired_blocks_from_content( $nested, $now );
        scblk_test( $changed && ! str_contains( $remaining, 'EXPIRED' ) && str_contains( $remaining, 'RECURRING_CONTENT' ) && str_contains( $remaining, 'SIBLING' ), 'nested deletion preserves recurring child and sibling' );

        wp_update_post( array( 'ID' => $post_id, 'post_content' => '<!-- wp:paragraph --><p>No schedule</p><!-- /wp:paragraph -->' ) );
        scblk_test( ! scblk_get_breeze_events_meta( $post_id ), 'editing out recurrence clears cache metadata' );
        foreach ( $events as $event ) {
                scblk_test( ! wp_next_scheduled( SCBLK_CRON_HOOK, array( $post_id, $event['type'], $event['ts'] ) ), 'editing out recurrence unschedules cron' );
        }
        echo $checks . " WordPress recurrence checks passed\n";
} finally {
        if ( is_int( $post_id ) && $post_id > 0 ) {
                scblk_breeze_unschedule_for_post( $post_id );
                scblk_delete_unschedule_for_post( $post_id );
                wp_delete_post( $post_id, true );
        }
        remove_action( 'breeze_clear_all_cache', $purge );
        foreach ( $saved as $option => $value ) {
                if ( null === $value ) delete_option( $option );
                else update_option( $option, $value );
        }
}
