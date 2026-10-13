<?php
/**
 * Integration checks against a disposable WordPress 6.9 site, with a fake AI provider.
 * Run: php tests/ai-integration.php /path/to/wordpress
 * Requires this plugin to be active and the optional AI Client plugin to be absent.
 * WordPress 7.0's built-in client cannot be replaced by this test double.
 * Never sends an external AI request.
 */
if ( empty( $argv[1] ) ) {
    fwrite( STDERR, "Usage: php ai-integration.php /path/to/wordpress\n" );
    exit( 1 );
}

// Define before WordPress loads: a test substitute for the optional AI Client.
function wp_ai_client_prompt( $prompt ) {
    SCBLK_Test_AI::$prompt = $prompt;
    return new SCBLK_Test_AI();
}
class SCBLK_Test_AI {
    public static $prompt;
    public static $instruction;
    public static $schema;
    public static $supported = true;
    public static $result;
    public static $calls = 0;
    public function using_system_instruction( $instruction ) { self::$instruction = $instruction; return $this; }
    public function using_max_tokens( $tokens ) { return $this; }
    public function as_json_response( $schema ) { self::$schema = $schema; return $this; }
    public function is_supported_for_text_generation() { return self::$supported; }
    public function generate_text() {
        ++self::$calls;
        if ( self::$result instanceof Throwable ) { throw self::$result; }
        return self::$result;
    }
}

require rtrim( $argv[1], '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( ! function_exists( 'scblk_ai_generate_draft' ) ) {
    throw new RuntimeException( 'Activate Scheduled Content Block before running these tests.' );
}
$count = 0;
function scblk_test( $condition, $message ) {
    global $count;
    if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
    ++$count;
    echo "PASS: $message\n";
}
function scblk_test_error( $result, $code, $message ) {
    scblk_test( is_wp_error( $result ) && $result->get_error_code() === $code, $message );
}
$previous_option = get_option( SCBLK_OPTION_AI_ENABLE, null );
$previous_timezone = get_option( 'timezone_string' );
$users = array();
$post_id = 0;
try {
    foreach ( array( 'administrator', 'contributor' ) as $role ) {
        $user_id = wp_insert_user( array(
            'user_login' => 'scblk-test-' . $role . '-' . uniqid(),
            'user_pass' => wp_generate_password( 32 ),
            'role' => $role,
        ) );
        if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
        $users[] = $user_id;
    }
    $post_id = wp_insert_post( array( 'post_title' => 'AI test', 'post_content' => 'Original content', 'post_author' => $users[0], 'post_status' => 'draft' ), true );
    if ( is_wp_error( $post_id ) ) { throw new RuntimeException( $post_id->get_error_message() ); }
    $input = array( 'post_id' => $post_id, 'prompt' => 'Write a weekend sale announcement from Friday at 9am to Sunday at 6pm.' );
    $draft = array( 'start' => '2026-10-16T09:00:00-05:00', 'end' => '2026-10-18T18:00:00-05:00', 'heading' => 'Weekend sale', 'paragraphs' => array( 'Save this weekend!' ) );
    SCBLK_Test_AI::$result = wp_json_encode( $draft );
    update_option( SCBLK_OPTION_AI_ENABLE, false );
    wp_set_current_user( $users[0] );
    scblk_test_error( scblk_ai_generate_draft( $input ), 'scblk_ai_disabled', 'disabled by default / site setting' );
    update_option( SCBLK_OPTION_AI_ENABLE, true );
    scblk_test_error( scblk_ai_generate_draft( $input ), 'scblk_ai_forbidden', 'AI capability required even for an administrator' );
    wp_get_current_user()->add_cap( 'prompt_ai' );
    wp_set_current_user( 0 );
    scblk_test_error( scblk_ai_generate_draft( $input ), 'scblk_ai_forbidden', 'anonymous users denied' );
    wp_set_current_user( $users[1] );
    wp_get_current_user()->add_cap( 'prompt_ai' );
    scblk_test_error( scblk_ai_generate_draft( $input ), 'scblk_ai_forbidden', 'AI permission alone cannot edit another author’s post' );
    scblk_test( SCBLK_Test_AI::$calls === 0, 'denied requests never contact provider' );
    wp_set_current_user( $users[0] );
    update_option( 'timezone_string', 'America/Chicago' );
    scblk_test( scblk_ai_generate_draft( $input ) === $draft, 'valid scheduled content draft generated' );
    scblk_test( SCBLK_Test_AI::$prompt === $input['prompt'] && ! str_contains( SCBLK_Test_AI::$instruction, 'Original content' ), 'existing post content never sent to provider' );
    scblk_test( str_contains( SCBLK_Test_AI::$instruction, 'America/Chicago' ) && preg_match( '/Current site time: \d{4}-\d{2}-\d{2}T/', SCBLK_Test_AI::$instruction ), 'site timezone and current time passed for relative schedules' );
    scblk_test( SCBLK_Test_AI::$schema === scblk_ai_draft_schema(), 'structured output schema passed to AI Client' );
    foreach ( array( '', '   ', str_repeat( 'a', 2001 ) ) as $prompt ) {
        scblk_test_error( scblk_ai_generate_draft( array_merge( $input, array( 'prompt' => $prompt ) ) ), 'scblk_ai_invalid_prompt', 'empty, blank, or oversized prompt rejected' );
    }
    SCBLK_Test_AI::$supported = false;
    scblk_test_error( scblk_ai_generate_draft( $input ), 'scblk_ai_unsupported', 'unconfigured or incompatible provider handled' );
    SCBLK_Test_AI::$supported = true;
    foreach ( array( new WP_Error( 'provider', 'private provider error' ), new RuntimeException( 'private credentials' ) ) as $error ) {
        SCBLK_Test_AI::$result = $error;
        $result = scblk_ai_generate_draft( $input );
        scblk_test_error( $result, 'scblk_ai_generation_failed', 'provider error / exception handled' );
        scblk_test( ! str_contains( $result->get_error_message(), 'private' ), 'provider internals not exposed to editor' );
    }
    foreach ( array( 'not JSON', 'null', '{}', wp_json_encode( array_merge( $draft, array( 'extra' => true ) ) ), wp_json_encode( array_merge( $draft, array( 'paragraphs' => array() ) ) ) ) as $bad ) {
        scblk_test_error( scblk_ai_validate_draft( $bad ), 'scblk_ai_invalid_draft', 'malformed or out-of-schema output rejected' );
    }
    foreach ( array( '2026-02-30T09:00:00Z', '2026-10-16T25:00:00Z', '2026-10-16T09:00:00', '2026-10-16T09:00:00+14:30', 'next Friday' ) as $date ) {
        scblk_test_error( scblk_ai_validate_draft( wp_json_encode( array_merge( $draft, array( 'start' => $date ) ) ) ), 'scblk_ai_invalid_draft', 'invalid or timezone-less start rejected' );
    }
    scblk_test_error( scblk_ai_validate_draft( wp_json_encode( array_merge( $draft, array( 'end' => '2026-10-01T00:00:00Z' ) ) ) ), 'scblk_ai_invalid_draft', 'reversed schedule rejected' );
    $open = array_merge( $draft, array( 'end' => '' ) );
    scblk_test( scblk_ai_validate_draft( wp_json_encode( $open ) ) === $open, 'open-ended schedule accepted' );
    $safe = scblk_ai_validate_draft( wp_json_encode( array_merge( $draft, array( 'heading' => '<b>Sale</b>', 'paragraphs' => array( '<script>alert(1)</script><p>Safe text</p>' ) ) ) ) );
    scblk_test( $safe['heading'] === 'Sale' && $safe['paragraphs'] === array( 'Safe text' ), 'generated HTML and scripts stripped' );
    SCBLK_Test_AI::$result = wp_json_encode( $draft );
    $request = new WP_REST_Request( 'POST', '/scheduled-content-block/v1/draft' );
    $request->set_body_params( $input );
    $response = rest_do_request( $request );
    scblk_test( $response->get_status() === 200 && $response->get_data() === $draft, 'authenticated editor REST route returns a draft' );
    $missing = new WP_REST_Request( 'POST', '/scheduled-content-block/v1/draft' );
    scblk_test( rest_do_request( $missing )->get_status() === 400, 'required REST arguments validated' );
    wp_set_current_user( 0 );
    scblk_test( rest_do_request( $request )->get_status() === 401, 'anonymous REST request denied' );
    wp_set_current_user( $users[0] );
    if ( function_exists( 'wp_get_ability' ) ) {
        $ability = wp_get_ability( 'scheduled-content-block/generate-draft' );
        scblk_test( null !== $ability, 'draft generation registered with Abilities API' );
        scblk_test( $ability->execute( $input ) === $draft, 'ability uses the same generation and validation' );
        wp_set_current_user( 0 );
        scblk_test( is_wp_error( $ability->execute( $input ) ), 'ability permission enforcement' );
    }
    scblk_test( get_post( $post_id )->post_content === 'Original content' && get_post( $post_id )->post_status === 'draft', 'generation never saves content or publishes the post' );
    scblk_test( get_post_meta( $post_id, SCBLK_META_DELETE_EVENTS, true ) === '', 'generation never enables deletion' );
    echo "$count integration checks passed (fake provider; no live AI request)\n";
} finally {
    wp_set_current_user( 0 );
    if ( $post_id && ! is_wp_error( $post_id ) ) { wp_delete_post( $post_id, true ); }
    foreach ( $users as $user_id ) { wp_delete_user( $user_id ); }
    if ( null === $previous_option ) { delete_option( SCBLK_OPTION_AI_ENABLE ); }
    else { update_option( SCBLK_OPTION_AI_ENABLE, $previous_option ); }
    update_option( 'timezone_string', $previous_timezone );
}
