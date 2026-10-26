<?php
/**
 * REST API Handler
 *
 * @package SmartAutoUploadImages\Admin
 */

namespace SmartAutoUploadImages\Admin;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use SmartAutoUploadImages\Services\UrlReplacer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Handler Class
 */
class RestApi {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	private string $namespace = 'smart-aui/v1';

	/**
	 * Settings manager.
	 *
	 * @var SettingsManager
	 */
	private SettingsManager $settings_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings_manager = \SmartAutoUploadImages\get_container()->get( 'settings_manager' );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/settings',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => $this->get_settings_schema(),
			]
		);

		register_rest_route(
			$this->namespace,
			'/settings/reset',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/preview-pattern',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preview_pattern' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'pattern' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/imports/history',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_import_history' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/imports/recover',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'recover_import' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'event_id' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Get the current user's recent import history.
	 *
	 * @return WP_REST_Response
	 */
	public function get_import_history(): WP_REST_Response {
		$history = get_option( 'smart_aui_import_history', [] );
		if ( ! is_array( $history ) ) {
			$history = [];
		}

		$visible_history = [];
		foreach ( $history as $event ) {
			$post_id = absint( $event['post_id'] ?? 0 );
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$visible_history[] = [
				'id'           => sanitize_text_field( $event['id'] ?? '' ),
				'post_id'      => $post_id,
				'post_title'   => sanitize_text_field( $event['post_title'] ?? '' ),
				'edit_url'     => get_edit_post_link( $post_id, 'raw' ),
				'imported_at'  => sanitize_text_field( $event['imported_at'] ?? '' ),
				'image_count'  => count( $event['mappings'] ?? [] ),
				'mappings'     => array_map(
					static function ( array $mapping ): array {
						return [
							'original_url' => esc_url_raw( $mapping['original_url'] ?? '' ),
							'imported_url' => esc_url_raw( $mapping['imported_url'] ?? '' ),
						];
					},
					$event['mappings'] ?? []
				),
				'recovered_at' => sanitize_text_field( $event['recovered_at'] ?? '' ),
			];
			if ( count( $visible_history ) >= 50 ) {
				break;
			}
		}

		return new WP_REST_Response( [ 'history' => $visible_history ], 200 );
	}

	/**
	 * Restore original image URLs for one import event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function recover_import( WP_REST_Request $request ) {
		$event_id = $request->get_param( 'event_id' );
		$history  = get_option( 'smart_aui_import_history', [] );
		if ( ! is_array( $history ) ) {
			$history = [];
		}

		$event_index = null;
		foreach ( $history as $index => $event ) {
			if ( ( $event['id'] ?? '' ) === $event_id ) {
				$event_index = $index;
				break;
			}
		}

		if ( null === $event_index ) {
			return new WP_Error( 'history_not_found', __( 'Import history entry not found.', 'smart-auto-upload-images' ), [ 'status' => 404 ] );
		}

		$event = $history[ $event_index ];
		$post  = get_post( $event['post_id'] ?? 0 );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'cannot_edit_post', __( 'You cannot edit this post.', 'smart-auto-upload-images' ), [ 'status' => 403 ] );
		}
		if ( ! empty( $event['recovered_at'] ) ) {
			return new WP_Error( 'already_recovered', __( 'This import has already been recovered.', 'smart-auto-upload-images' ), [ 'status' => 409 ] );
		}

		$replacements = [];
		foreach ( $event['mappings'] ?? [] as $mapping ) {
			$imported_url = $mapping['imported_url'] ?? '';
			$original_url = esc_url_raw( $mapping['original_url'] ?? '' );
			if ( '' === $imported_url || '' === $original_url ) {
				continue;
			}

			if ( isset( $replacements[ $imported_url ] ) && $replacements[ $imported_url ] !== $original_url ) {
				return new WP_Error( 'ambiguous_import', __( 'This import contains a shared image URL with different originals and cannot be restored safely.', 'smart-auto-upload-images' ), [ 'status' => 409 ] );
			}
			$replacements[ $imported_url ] = $original_url;
		}

		$replacement    = UrlReplacer::replace( $post->post_content, $replacements );
		$content        = $replacement['content'];
		$restored_count = count( $replacement['replaced_urls'] );

		if ( 0 === $restored_count ) {
			return new WP_Error( 'nothing_to_restore', __( 'None of this import’s URLs are still present in the post.', 'smart-auto-upload-images' ), [ 'status' => 409 ] );
		}

		$plugin  = \SmartAutoUploadImages\get_container()->get( 'plugin' );
		$updated = $plugin->update_post_content_without_import( $post->ID, $content );
		if ( is_wp_error( $updated ) || ! $updated ) {
			return new WP_Error( 'recovery_failed', __( 'The post could not be updated. No URLs were restored.', 'smart-auto-upload-images' ), [ 'status' => 500 ] );
		}

		$history[ $event_index ]['recovered_at'] = current_time( 'mysql', true );
		update_option( 'smart_aui_import_history', $history, false );

		return new WP_REST_Response(
			[
				'post_id'        => $post->ID,
				'restored_count' => $restored_count,
				'recovered_at'   => $history[ $event_index ]['recovered_at'],
			],
			200
		);
	}

	/**
	 * Get settings callback.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$settings = $this->settings_manager->get_settings();
		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * Update settings callback.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$new_settings = $this->settings_manager->sanitize_settings( $request->get_json_params() );

		// Validate settings.
		$errors = $this->settings_manager->validate_settings( $new_settings );
		if ( ! empty( $errors ) ) {
			return new WP_Error( 'validation_failed', 'Settings validation failed', $errors );
		}

		// Update settings.
		$result = $this->settings_manager->update_settings( $new_settings );

		if ( $result ) {
			$updated_settings = $this->settings_manager->get_settings();
			return new WP_REST_Response( $updated_settings, 200 );
		}

		return new WP_Error( 'update_failed', 'Failed to update settings', [ 'status' => 500 ] );
	}

	/**
	 * Reset settings callback.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_settings() {
		$result = $this->settings_manager->reset_settings();

		if ( $result ) {
			$settings = $this->settings_manager->get_settings();
			return new WP_REST_Response( $settings, 200 );
		}

		return new WP_Error( 'reset_failed', 'Failed to reset settings', [ 'status' => 500 ] );
	}

	/**
	 * Preview pattern callback.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function preview_pattern( WP_REST_Request $request ): WP_REST_Response {
		$pattern = $request->get_param( 'pattern' );

		// Create mock data for preview.
		$mock_data = [
			'post_title'  => 'Sample Blog Post',
			'post_id'     => 123,
			'post_name'   => 'sample-blog-post',
			'post_date'   => current_time( 'mysql' ),
			'image_alt'   => 'Sample Alt Text',
			'image_title' => 'Sample Image Title',
			'filename'    => 'sample-image',
		];

		$pattern_resolver = new \SmartAutoUploadImages\Services\PatternResolver();
		$preview          = $pattern_resolver->resolve_pattern( $pattern, $mock_data );

		return new WP_REST_Response( [ 'preview' => $preview ], 200 );
	}

	/**
	 * Check permissions.
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get settings schema.
	 *
	 * @return array
	 */
	private function get_settings_schema(): array {
		return [
			'base_url'           => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'image_name_pattern' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'alt_text_pattern'   => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'max_width'          => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'max_height'         => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'exclude_post_types' => [
				'type'  => 'array',
				'items' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
			'exclude_domains'    => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
		];
	}
}
