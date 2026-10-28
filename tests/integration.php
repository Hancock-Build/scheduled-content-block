<?php
/** Run: php tests/integration.php inside the local WordPress container. */
if ( PHP_SAPI !== 'cli' ) exit;
require ( getenv( 'SCBLK_WP_ROOT' ) ?: '/var/www/html' ) . '/wp-load.php';
$count = 0;
function check( $condition, $label ) {
    global $count;
    if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $label );
    ++$count;
    echo "PASS: $label\n";
}
function container_block( $attrs, $content ) {
    return '<!-- wp:h-b/scheduled-container ' . wp_json_encode( $attrs ) . ' -->' . $content . '<!-- /wp:h-b/scheduled-container -->';
}
function paragraph( $text ) { return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->'; }
$original_roles = get_option( SCBLK_OPTION_VISIBILITY, null );
$original_timezone = get_option( 'timezone_string' );
$original_offset = get_option( 'gmt_offset' );
$posts = array();
$now = time();
try {
    check( $GLOBALS['wp_version'] === '7.1.4', 'WordPress 7.1.4 loaded' );
    check( WP_Block_Type_Registry::get_instance()->is_registered( 'h-b/scheduled-container' ), 'block registered' );
    $all = array_keys( wp_roles()->roles );
    check( scblk_sanitize_visibility_roles( $all ) === $all, 'all built-in roles survive sanitizing' );
    add_role( 'scblk_test_role', 'Test role', array( 'read' => true ) );
    check( scblk_sanitize_visibility_roles( array( 'scblk_test_role', 'visitor', 'invalid' ) ) === array( 'scblk_test_role', 'visitor' ), 'custom roles and visitors accepted; invalid role rejected' );
    check( scblk_sanitize_visibility_roles( array( '' ) ) === array(), 'unchecking every role persists empty selection' );
    check( scblk_visibility_default_roles() === array( 'administrator' ), 'default bypass remains administrators only' );
    $admin = get_user_by( 'login', 'developer' );
    wp_set_current_user( $admin->ID );
    check( scblk_user_can_bypass_schedule(), 'administrator bypass' );
    wp_set_current_user( 0 );
    update_option( SCBLK_OPTION_VISIBILITY, array( 'visitor' ) );
    check( scblk_user_can_bypass_schedule(), 'visitor bypass' );
    update_option( SCBLK_OPTION_VISIBILITY, array() );
    check( ! scblk_user_can_bypass_schedule(), 'empty roles disables bypass' );
    update_option( 'timezone_string', 'America/Chicago' );
    check( scblk_parse_site_ts( '2026-07-01T12:00:00' ) === strtotime( '2026-07-01T17:00:00Z' ), 'summer site timezone conversion' );
    check( scblk_parse_site_ts( '2026-01-01T12:00:00' ) === strtotime( '2026-01-01T18:00:00Z' ), 'winter site timezone conversion' );
    check( scblk_parse_site_ts( '2026-07-01T12:00:00Z' ) === strtotime( '2026-07-01T12:00:00Z' ), 'absolute dates preserved' );
    $marker = '<p>VISIBLE</p>';
    foreach ( array(
        'unscheduled' => array( array(), $marker ),
        'active' => array( array( 'start' => gmdate( 'c', $now - 60 ), 'end' => gmdate( 'c', $now + 60 ) ), $marker ),
        'future' => array( array( 'start' => gmdate( 'c', $now + 60 ) ), '' ),
        'expired' => array( array( 'end' => gmdate( 'c', $now - 60 ) ), '' ),
        'invalid range' => array( array( 'start' => gmdate( 'c', $now + 60 ), 'end' => gmdate( 'c', $now - 60 ) ), '' ),
        'invalid date' => array( array( 'start' => 'bad-date' ), '' ),
        'escaped placeholder' => array( array( 'start' => gmdate( 'c', $now + 60 ), 'showPlaceholder' => true, 'placeholderText' => '<soon>' ), '<div class="scblk-placeholder" aria-hidden="true">&lt;soon&gt;</div>' ),
    ) as $label => $case ) {
        check( scblk_render_callback( $case[0], $marker, null ) === $case[1], 'render ' . $label );
    }
    $expired = container_block( array( 'deleteAfterEnd' => true, 'end' => gmdate( 'c', $now - 60 ) ), paragraph( 'REMOVE' ) );
    $keep = container_block( array( 'deleteAfterEnd' => false, 'end' => gmdate( 'c', $now - 60 ) ), paragraph( 'KEEP' ) );
    $future = container_block( array( 'deleteAfterEnd' => true, 'end' => gmdate( 'c', $now + 3600 ) ), paragraph( 'FUTURE' ) );
    foreach ( array( 0, 1, 2 ) as $position ) {
        $children = array( paragraph( 'FIRST' ), paragraph( 'LAST' ) );
        array_splice( $children, $position, 0, array( $expired ) );
        $content = '<!-- wp:group --><div class="wp-block-group">' . implode( "\n", $children ) . '</div><!-- /wp:group -->';
        list( $clean, $changed ) = scblk_remove_expired_blocks_from_content( $content, $now );
        check( $changed && ! str_contains( $clean, 'REMOVE' ) && str_contains( $clean, 'FIRST' ) && str_contains( $clean, 'LAST' ), 'nested deletion at sibling position ' . $position );
        check( serialize_blocks( parse_blocks( $clean ) ) === $clean, 'nested content round-trips at position ' . $position );
    }
    $content = '<!-- wp:group --><div class="wp-block-group">' . $expired . '<!-- wp:group --><div class="wp-block-group">' . $expired . $keep . $future . '</div><!-- /wp:group -->' . $expired . '</div><!-- /wp:group -->';
    list( $clean, $changed ) = scblk_remove_expired_blocks_from_content( $content, $now );
    check( $changed && ! str_contains( $clean, 'REMOVE' ) && str_contains( $clean, 'KEEP' ) && str_contains( $clean, 'FUTURE' ), 'adjacent and deeply nested deletion preserves unexpired and opted-out blocks' );
    check( scblk_remove_expired_blocks_from_content( $clean, $now ) === array( $clean, false ), 'deletion is idempotent' );
    check( scblk_remove_expired_blocks_from_content( 'Plain text', $now ) === array( 'Plain text', false ), 'plain content unchanged' );

    wp_set_current_user( $admin->ID );
    $id = wp_insert_post( array( 'post_title' => 'SCBLK integration', 'post_status' => 'publish', 'post_content' => wp_slash( $future . paragraph( 'Path C:\\demo' ) ) ) );
    $posts[] = $id;
    check( count( scblk_get_delete_events_meta( $id ) ) === 1, 'future deletion registered on save' );
    // Preserve a future scheduled snapshot, then expire it in the callback.
    $before = get_post( $id )->post_content;
    scblk_handle_delete_expired_blocks( $id, $now );
    check( get_post( $id )->post_content === $before, 'early callback does not delete future content' );
    scblk_save_deleted_content( $id, paragraph( 'Path C:\\demo' ) );
    check( str_contains( get_post( $id )->post_content, 'C:\\demo' ), 'deletion save preserves literal backslashes' );
    $revisions = wp_get_post_revisions( $id );
    check( (bool) array_filter( $revisions, function ( $r ) use ( $before ) { return $r->post_content === $before; } ), 'pre-deletion content exists in revisions' );
    // Exercise the actual cron deletion path with an expired block stored directly.
    global $wpdb;
    $wpdb->update( $wpdb->posts, array( 'post_content' => $expired . paragraph( 'SURVIVOR' ) ), array( 'ID' => $id ) );
    clean_post_cache( $id );
    scblk_handle_delete_expired_blocks( $id, $now );
    check( ! str_contains( get_post( $id )->post_content, 'REMOVE' ) && str_contains( get_post( $id )->post_content, 'SURVIVOR' ), 'cron removes expired block and preserves siblings' );
    scblk_delete_unschedule_for_post( $id );
    check( ! scblk_get_delete_events_meta( $id ), 'deletion events cleaned up' );
    // Recovery: copy the retained content and opt out before saving it again.
    $recovered = str_replace( '"deleteAfterEnd":true', '"deleteAfterEnd":false', $expired . paragraph( 'SURVIVOR' ) );
    wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $recovered ) ) );
    check( get_post( $id )->post_content === $recovered, 'copied revision content can be saved with deletion disabled' );
    add_filter( 'wp_revisions_to_keep', '__return_zero' );
    scblk_save_deleted_content( $id, paragraph( 'REVISIONS_DISABLED' ) );
    remove_filter( 'wp_revisions_to_keep', '__return_zero' );
    check( str_contains( get_post( $id )->post_content, 'REVISIONS_DISABLED' ), 'deletion save works when revisions disabled' );
    do_action( 'enqueue_block_editor_assets' );
    check( in_array( 'wp-date', wp_scripts()->registered['scblk-editor']->deps, true ), 'WordPress date dependency explicit' );
    check( in_array( 'scblk-schedule', wp_scripts()->registered['scblk-editor']->deps, true ), 'scheduling helper dependency explicit' );
    check( ! str_contains( do_blocks( $keep ), 'scblk-visitor-preview' ), 'frontend has no editor preview markup' );
    echo "$count integration checks passed on WordPress {$GLOBALS['wp_version']}, PHP " . PHP_VERSION . "\n";
} finally {
    foreach ( $posts as $id ) { scblk_delete_unschedule_for_post( $id ); wp_delete_post( $id, true ); }
    remove_role( 'scblk_test_role' );
    if ( null === $original_roles ) delete_option( SCBLK_OPTION_VISIBILITY );
    else update_option( SCBLK_OPTION_VISIBILITY, $original_roles );
    update_option( 'timezone_string', $original_timezone );
    update_option( 'gmt_offset', $original_offset );
}
