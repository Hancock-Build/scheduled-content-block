<?php
/** Local test fixture only. Redirect output to a private temporary JSON file. */
if ( PHP_SAPI !== 'cli' || getenv( 'SCBLK_TEST_SITE' ) !== '1' ) exit( 'Set SCBLK_TEST_SITE=1 in an isolated local test installation.' );
require ( getenv( 'SCBLK_WP_ROOT' ) ?: '/var/www/html' ) . '/wp-load.php';
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost' ), true ) ) exit( 'A loopback test site is required.' );
$password = bin2hex( random_bytes( 24 ) );
$username = 'scblk_browser_test';
$user = get_user_by( 'login', $username );
if ( $user ) wp_set_password( $password, $user->ID );
else {
    $id = wp_insert_user( array( 'user_login' => $username, 'user_pass' => $password, 'role' => 'administrator' ) );
    if ( is_wp_error( $id ) ) throw new RuntimeException( $id->get_error_message() );
    $user = get_user_by( 'id', $id );
}
wp_set_current_user( $user->ID );
update_option( 'timezone_string', 'America/Chicago' );
update_option( SCBLK_OPTION_VISIBILITY, array( 'administrator' ) );
$post = wp_insert_post( array( 'post_title' => 'SCBLK browser fixture', 'post_status' => 'draft' ) );
echo wp_json_encode( array( 'baseURL' => home_url(), 'username' => $username, 'password' => $password, 'postId' => $post ) );
