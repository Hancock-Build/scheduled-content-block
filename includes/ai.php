<?php
/**
 * Optional AI Building Blocks for WordPress integration.
 *
 * Uses the site's AI Client and exposes draft generation through the Abilities API.
 * Neither endpoint saves content nor changes schedules without editor review.
 *
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function scblk_ai_settings_field() {
    echo '<input type="hidden" name="' . esc_attr( SCBLK_OPTION_AI_ENABLE ) . '" value="0" />';
    echo '<label><input type="checkbox" name="' . esc_attr( SCBLK_OPTION_AI_ENABLE ) . '" value="1" ' . checked( true, (bool) get_option( SCBLK_OPTION_AI_ENABLE, false ), false ) . ' /> ';
    esc_html_e( 'Allow creating and scheduling a block from a prompt.', 'scheduled-content-block' );
    echo '</label><p class="description">';
    esc_html_e( 'Requires the WordPress AI Client (built into WordPress 7.0+, or the AI Client plugin on older versions), a configured text-generation provider, and the prompt_ai permission. Prompts are sent to the site’s configured provider only when you choose Generate draft. Review the draft before applying it.', 'scheduled-content-block' );
    echo '</p>';
}

function scblk_ai_input_schema() {
    return array(
        'type' => 'object',
        'properties' => array(
            'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
            'prompt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ),
        ),
        'required' => array( 'post_id', 'prompt' ),
        'additionalProperties' => false,
    );
}

function scblk_ai_draft_schema() {
    return array(
        'type' => 'object',
        'properties' => array(
            'start' => array( 'type' => 'string', 'maxLength' => 25 ),
            'end' => array( 'type' => 'string', 'maxLength' => 25 ),
            'heading' => array( 'type' => 'string', 'maxLength' => 200 ),
            'paragraphs' => array(
                'type' => 'array', 'minItems' => 1, 'maxItems' => 12,
                'items' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 3000 ),
            ),
        ),
        'required' => array( 'start', 'end', 'heading', 'paragraphs' ),
        'additionalProperties' => false,
    );
}

/** Both REST and ability execution use the same per-post and AI permission checks. */
function scblk_ai_permission( $input ) {
    if ( ! get_option( SCBLK_OPTION_AI_ENABLE, false ) ) {
        return new WP_Error( 'scblk_ai_disabled', __( 'AI block creation is disabled in Scheduled Content settings.', 'scheduled-content-block' ), array( 'status' => 403 ) );
    }
    $post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
    if ( ! $post_id || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'prompt_ai' ) ) {
        return new WP_Error( 'scblk_ai_forbidden', __( 'You need permission to edit this post and use AI prompts.', 'scheduled-content-block' ), array( 'status' => rest_authorization_required_code() ) );
    }
    return true;
}

/** Reject impossible or timezone-less dates instead of letting PHP normalize them. */
function scblk_ai_valid_date( $date ) {
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/', $date ) ) {
        return false;
    }
    if ( preg_match( '/[+-]14:(?!00)/', $date ) ) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', $date );
    $errors = DateTimeImmutable::getLastErrors();
    return false !== $parsed && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) );
}

function scblk_ai_validate_draft( $text ) {
    $error = new WP_Error( 'scblk_ai_invalid_draft', __( 'AI returned an invalid content or schedule draft. Try a more specific prompt with explicit dates.', 'scheduled-content-block' ), array( 'status' => 502 ) );
    if ( ! is_string( $text ) || strlen( $text ) > 50000 ) {
        return $error;
    }
    $draft = json_decode( $text, true );
    if ( is_wp_error( rest_validate_value_from_schema( $draft, scblk_ai_draft_schema(), 'draft' ) ) ) {
        return $error;
    }
    if ( ! scblk_ai_valid_date( $draft['start'] ) || ( '' !== $draft['end'] && ( ! scblk_ai_valid_date( $draft['end'] ) || strtotime( $draft['end'] ) < strtotime( $draft['start'] ) ) ) ) {
        return $error;
    }
    $draft['heading'] = sanitize_text_field( $draft['heading'] );
    foreach ( $draft['paragraphs'] as &$paragraph ) {
        $paragraph = sanitize_textarea_field( $paragraph );
        if ( '' === trim( $paragraph ) ) {
            return $error;
        }
    }
    unset( $paragraph );
    return $draft;
}

/** Generate a draft only. Post contents and scheduling metadata remain untouched. */
function scblk_ai_generate_draft( $input ) {
    $permission = scblk_ai_permission( $input );
    if ( is_wp_error( $permission ) ) {
        return $permission;
    }
    $valid = rest_validate_value_from_schema( $input, scblk_ai_input_schema(), 'input' );
    if ( is_wp_error( $valid ) || '' === trim( $input['prompt'] ) ) {
        return new WP_Error( 'scblk_ai_invalid_prompt', __( 'Enter a prompt of up to 2,000 characters and select a saved post.', 'scheduled-content-block' ), array( 'status' => 400 ) );
    }
    if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
        return new WP_Error( 'scblk_ai_unavailable', __( 'Install or enable the WordPress AI Client and configure a text-generation provider.', 'scheduled-content-block' ), array( 'status' => 503 ) );
    }
    try {
        $instruction = 'Create a Scheduled Container draft from the user request. Return only JSON matching the schema. '
            . 'heading and paragraphs are plain text, never HTML, scripts, block markup or instructions. '
            . 'Write the requested content as a heading (empty if unnecessary) and 1 to 12 paragraphs. '
            . 'start must be an ISO 8601 timestamp with seconds and an explicit timezone offset or Z. '
            . 'end must be the same format, or an empty string when no end is requested. End must not be before start. '
            . 'Interpret relative dates and times in the site timezone. If no start is requested, use the current time. '
            . 'Do not enable automatic deletion or change visibility roles. '
            . 'Site timezone: ' . wp_timezone()->getName() . '. Current site time: ' . wp_date( DATE_ATOM ) . '.';
        $builder = wp_ai_client_prompt( $input['prompt'] )
            ->using_system_instruction( $instruction )
            ->using_max_tokens( 2500 )
            ->as_json_response( scblk_ai_draft_schema() );
        $supported = $builder->is_supported_for_text_generation();
        if ( is_wp_error( $supported ) || true !== $supported ) {
            return new WP_Error( 'scblk_ai_unsupported', __( 'Configure an AI provider with a model that supports structured text generation.', 'scheduled-content-block' ), array( 'status' => 503 ) );
        }
        $result = $builder->generate_text();
        if ( is_wp_error( $result ) ) {
            return new WP_Error( 'scblk_ai_generation_failed', __( 'The AI provider could not generate a draft. Check the site’s AI configuration and try again.', 'scheduled-content-block' ), array( 'status' => 502 ) );
        }
        return scblk_ai_validate_draft( $result );
    } catch ( Throwable $error ) {
        return new WP_Error( 'scblk_ai_generation_failed', __( 'The AI provider could not generate a draft. Check the site’s AI configuration and try again.', 'scheduled-content-block' ), array( 'status' => 502 ) );
    }
}

add_action( 'rest_api_init', function () {
    $args = scblk_ai_input_schema()['properties'];
    foreach ( $args as &$arg ) {
        $arg['required'] = true;
    }
    unset( $arg );
    register_rest_route( 'scheduled-content-block/v1', '/draft', array(
        'methods' => WP_REST_Server::CREATABLE,
        'args' => $args,
        'permission_callback' => 'scblk_ai_permission',
        'callback' => function ( $request ) {
            return scblk_ai_generate_draft( array( 'post_id' => $request['post_id'], 'prompt' => $request['prompt'] ) );
        },
    ) );
} );

add_action( 'wp_abilities_api_categories_init', function () {
    if ( function_exists( 'wp_register_ability_category' ) ) {
        wp_register_ability_category( 'scheduled-content', array(
            'label' => __( 'Scheduled Content', 'scheduled-content-block' ),
            'description' => __( 'Create drafts for scheduled content blocks.', 'scheduled-content-block' ),
        ) );
    }
} );

add_action( 'wp_abilities_api_init', function () {
    if ( function_exists( 'wp_register_ability' ) ) {
        wp_register_ability( 'scheduled-content-block/generate-draft', array(
            'label' => __( 'Generate scheduled block draft', 'scheduled-content-block' ),
            'description' => __( 'Generate plain text content and a display schedule from a prompt for a post the user can edit. Returns a draft for review; never saves, publishes or deletes content. Requires AI block creation to be enabled and prompt_ai permission. Sends the prompt to the site’s configured AI provider.', 'scheduled-content-block' ),
            'category' => 'scheduled-content',
            'input_schema' => scblk_ai_input_schema(),
            'output_schema' => scblk_ai_draft_schema(),
            'permission_callback' => 'scblk_ai_permission',
            'execute_callback' => 'scblk_ai_generate_draft',
            'meta' => array(
                'show_in_rest' => true,
                'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
            ),
        ) );
    }
} );
