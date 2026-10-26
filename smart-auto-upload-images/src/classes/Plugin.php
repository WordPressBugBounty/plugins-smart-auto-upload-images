<?php
/**
 * Main Plugin Class
 *
 * @package SmartAutoUploadImages
 */

namespace SmartAutoUploadImages;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Class
 */
class Plugin {
	/**
	 * Imports are disabled temporarily while recovering original content.
	 *
	 * @var bool
	 */
	private bool $image_processing_enabled = true;

	/**
	 * Imports waiting for their post to finish saving.
	 *
	 * @var array
	 */
	private array $pending_image_imports = [];

	/**
	 * Imports from a new post save, whose post ID is not available in the data filter.
	 *
	 * @var array|null
	 */
	private ?array $pending_new_post_import = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initialize the plugin
	 */
	private function init(): void {
		$this->setup_hooks();
	}

	/**
	 * Setup hooks
	 */
	private function setup_hooks(): void {
		// Post processing hooks.
		add_filter( 'wp_insert_post_data', [ $this, 'process_post_images' ], 10, 2 );
		add_action( 'wp_after_insert_post', [ $this, 'record_saved_post_imports' ], 10, 2 );
		add_action( 'init', [ $this, 'register_custom_post_fields' ] );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_scripts' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_filter( 'plugin_action_links_' . SMART_AUI_PLUGIN_BASENAME, [ $this, 'add_settings_link' ] );
	}

	/**
	 * Get plugin settings with defaults
	 *
	 * @return array Plugin settings with defaults applied
	 */
	public static function get_settings(): array {
		$defaults = [
			'base_url'           => site_url(),
			'image_name_pattern' => '%filename%',
			'alt_text_pattern'   => '%image_alt%',
			'max_width'          => 0,
			'max_height'         => 0,
			'exclude_post_types' => [],
			'exclude_domains'    => '',
			'version'            => SMART_AUI_VERSION,
		];

		$stored_settings = get_option( 'smart_aui_settings', [] );
		return wp_parse_args( $stored_settings, $defaults );
	}

	/**
	 * Process post images
	 *
	 * @param array $data Post data.
	 * @param array $postarr Post array.
	 * @return array Modified post data.
	 */
	public function process_post_images( array $data, array $postarr ): array {
		if ( ! $this->image_processing_enabled ) {
			return $data;
		}

		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $data;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return $data;
		}

		if ( wp_is_post_autosave( $postarr['ID'] ) ) {
			return $data;
		}

		if ( 'revision' === $data['post_type'] ) {
			return $data;
		}

		// Process images.
		$image_processor = \SmartAutoUploadImages\get_container()->get( 'image_processor' );
		$process_result  = $image_processor->process_post_content_with_history( wp_unslash( $data['post_content'] ), $postarr );

		if ( false !== $process_result ) {
			$data['post_content'] = wp_slash( $process_result['content'] );
			if ( ! empty( $process_result['mappings'] ) ) {
				$post_id = absint( $postarr['ID'] ?? 0 );
				if ( $post_id ) {
					$this->pending_image_imports[ $post_id ] = $process_result['mappings'];
				} else {
					$this->pending_new_post_import = [
						'post_type' => $data['post_type'],
						'mappings'  => $process_result['mappings'],
					];
				}
			}
		}

		return $data;
	}

	/**
	 * Save import history after a post containing successfully imported images is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 */
	public function record_saved_post_imports( int $post_id, \WP_Post $post ): void {
		$mappings = $this->pending_image_imports[ $post_id ] ?? null;
		unset( $this->pending_image_imports[ $post_id ] );

		if ( $this->pending_new_post_import ) {
			if ( ! $mappings && $post->post_type === $this->pending_new_post_import['post_type'] ) {
				$mappings = $this->pending_new_post_import['mappings'];
			}
			$this->pending_new_post_import = null;
		}

		if ( $mappings ) {
			$this->record_import_history( $post_id, $mappings );
		}
	}

	/**
	 * Add a successful import to the site's recent history.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $mappings Original and imported URL pairs.
	 */
	public function record_import_history( int $post_id, array $mappings ): void {
		$post = get_post( $post_id );
		if ( ! $post || empty( $mappings ) ) {
			return;
		}

		$history = get_option( 'smart_aui_import_history', [] );
		if ( ! is_array( $history ) ) {
			$history = [];
		}

		array_unshift(
			$history,
			[
				'id'           => wp_generate_uuid4(),
				'post_id'      => $post_id,
				'post_title'   => get_the_title( $post ),
				'imported_at'  => current_time( 'mysql', true ),
				'mappings'     => $mappings,
				'recovered_at' => '',
			]
		);

		update_option( 'smart_aui_import_history', array_slice( $history, 0, 500 ), false );
	}

	/**
	 * Update post content without running automatic image imports.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content New post content.
	 * @return int|\WP_Error
	 */
	public function update_post_content_without_import( int $post_id, string $content ) {
		$was_enabled                    = $this->image_processing_enabled;
		$this->image_processing_enabled = false;

		try {
			return wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => wp_slash( $content ),
				],
				true
			);
		} finally {
			$this->image_processing_enabled = $was_enabled;
		}
	}

	/**
	 * Register custom post fields
	 */
	public function register_custom_post_fields(): void {
		register_rest_field(
			[ 'post', 'page' ],
			'smart_aui_featured_image_url',
			[
				'update_callback' => [ $this, 'update_featured_image_url_field' ],
			]
		);
	}

	/**
	 * Update featured image URL field value
	 *
	 * @param string $value New value.
	 * @param object $post  Post object.
	 * @return bool True on success.
	 */
	public function update_featured_image_url_field( string $value, $post ): bool {
		$image_downloader = \SmartAutoUploadImages\get_container()->get( 'image_downloader' );
		$result           = $image_downloader->download_image( [ 'url' => $value ], [ 'ID' => $post->ID ] );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$attachment_id = $result['attachment_id'];
		set_post_thumbnail( $post->ID, $attachment_id );
		return true;
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_admin_scripts( string $hook_suffix ): void {
		// Only load on our settings page.
		if ( 'settings_page_smart-auto-upload-images' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'smart-aui-admin-settings',
			SMART_AUI_PLUGIN_URL . 'dist/js/admin-settings.js',
			\SmartAutoUploadImages\Utils\get_asset_info( 'admin-settings', 'dependencies' ),
			\SmartAutoUploadImages\Utils\get_asset_info( 'admin-settings', 'version' ),
			true
		);

		wp_localize_script(
			'smart-aui-admin-settings',
			'smartAuiAdmin',
			[
				'postTypes' => $this->get_formatted_post_types(),
			]
		);

		wp_enqueue_style(
			'smart-aui-admin-settings-style',
			SMART_AUI_PLUGIN_URL . 'dist/css/admin-settings-style.css',
			[ 'wp-components' ],
			\SmartAutoUploadImages\Utils\get_asset_info( 'admin-settings-style', 'version' ),
		);
	}

	/**
	 * Enqueue editor scripts
	 */
	public function enqueue_editor_scripts(): void {
		wp_enqueue_script(
			'smart-aui-editor',
			SMART_AUI_PLUGIN_URL . 'dist/js/admin-editor.js',
			\SmartAutoUploadImages\Utils\get_asset_info( 'admin-editor', 'dependencies' ),
			\SmartAutoUploadImages\Utils\get_asset_info( 'admin-editor', 'version' ),
			true
		);
	}

	/**
	 * Register admin menu
	 */
	public function register_admin_menu(): void {
		add_options_page(
			__( 'Smart Auto Upload Images Settings', 'smart-auto-upload-images' ),
			__( 'Smart Auto Upload Images', 'smart-auto-upload-images' ),
			'manage_options',
			'smart-auto-upload-images',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page(): void {
		echo '<div id="smart-aui-admin-root"></div>';
	}

	/**
	 * Register REST API routes
	 */
	public function register_rest_routes(): void {
		$rest_api = new \SmartAutoUploadImages\Admin\RestApi();
		$rest_api->register_routes();
	}

	/**
	 * Add settings link to plugin actions
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'options-general.php?page=smart-auto-upload-images' ),
			esc_html__( 'Settings', 'smart-auto-upload-images' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Get formatted post types for frontend
	 *
	 * @return array Formatted post types array
	 */
	public function get_formatted_post_types(): array {
		$post_types           = get_post_types( [ 'public' => true ], 'objects' );
		$formatted_post_types = [];

		unset( $post_types['attachment'] );

		foreach ( $post_types as $post_type ) {
			$formatted_post_types[] = [
				'value' => $post_type->name,
				'label' => $post_type->label,
			];
		}

		return $formatted_post_types;
	}
}
