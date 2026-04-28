<?php
/**
 * Plugin Name:       Zillha Avatar
 * Plugin URI:        https://zillha.games/
 * Description:       Two front-end shortcodes for setting a user's avatar: a manual uploader and an AI generator that calls an n8n webhook. Both modes share one media-library save flow and one Gravatar replacement filter.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ZillHa Games
 * Author URI:        https://zillha.games/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zillha-avatar
 * Domain Path:       /languages
 *
 * @package Zillha_Avatar
 */

defined( 'ABSPATH' ) || exit;

define( 'ZILLHA_AVATAR_VERSION', '1.0.0' );
define( 'ZILLHA_AVATAR_FILE', __FILE__ );
define( 'ZILLHA_AVATAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZILLHA_AVATAR_URL', plugin_dir_url( __FILE__ ) );
define( 'ZILLHA_AVATAR_BASENAME', plugin_basename( __FILE__ ) );

define( 'ZILLHA_AVATAR_OPTION_KEY', 'zillha_avatar_options' );
define( 'ZILLHA_AVATAR_USER_META_KEY', 'zillha_avatar_attachment_id' );
define( 'ZILLHA_AVATAR_PENDING_META_KEY', 'zillha_avatar_pending_token' );
define( 'ZILLHA_AVATAR_PENDING_DIR_NAME', 'zillha-pending' );
define( 'ZILLHA_AVATAR_PENDING_TTL', 10 * MINUTE_IN_SECONDS );
define( 'ZILLHA_AVATAR_PENDING_TOKEN_LENGTH', 32 );
define( 'ZILLHA_AVATAR_WEBHOOK_TIMEOUT', 120 );
define( 'ZILLHA_AVATAR_WEBHOOK_MAX_BYTES', 4194304 );
define( 'ZILLHA_AVATAR_TARGET_SIZE', 400 );
define( 'ZILLHA_AVATAR_MAX_UPLOAD_BYTES', 5 * 1024 * 1024 );

require_once ZILLHA_AVATAR_DIR . 'includes/class-zillha-avatar-settings.php';
require_once ZILLHA_AVATAR_DIR . 'includes/class-zillha-avatar-save.php';
require_once ZILLHA_AVATAR_DIR . 'includes/class-zillha-avatar-ajax.php';
require_once ZILLHA_AVATAR_DIR . 'includes/class-zillha-avatar-shortcode-generator.php';
require_once ZILLHA_AVATAR_DIR . 'includes/class-zillha-avatar-shortcode-uploader.php';
require_once ZILLHA_AVATAR_DIR . 'includes/class-zillha-avatar-filter.php';

/**
 * Singleton bootstrap. Owns constants, helpers, activation/deactivation hooks
 * and the WP-Cron cleanup job for pending generated avatars.
 */
final class Zillha_Avatar_Plugin {

	const CLEANUP_HOOK = 'zillha_avatar_pending_cleanup';
	const ASSET_HANDLE = 'zillha-avatar';

	/**
	 * Singleton instance.
	 *
	 * @var Zillha_Avatar_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings handler.
	 *
	 * @var Zillha_Avatar_Settings
	 */
	public $settings;

	/**
	 * AJAX handler.
	 *
	 * @var Zillha_Avatar_Ajax
	 */
	public $ajax;

	/**
	 * Generator shortcode handler.
	 *
	 * @var Zillha_Avatar_Shortcode_Generator
	 */
	public $shortcode_generator;

	/**
	 * Uploader shortcode handler.
	 *
	 * @var Zillha_Avatar_Shortcode_Uploader
	 */
	public $shortcode_uploader;

	/**
	 * Avatar URL filter.
	 *
	 * @var Zillha_Avatar_Filter
	 */
	public $filter;

	/**
	 * Get singleton instance.
	 *
	 * @return Zillha_Avatar_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: build subsystems and register lifecycle hooks.
	 */
	private function __construct() {
		$this->settings            = new Zillha_Avatar_Settings();
		$this->ajax                = new Zillha_Avatar_Ajax();
		$this->shortcode_generator = new Zillha_Avatar_Shortcode_Generator();
		$this->shortcode_uploader  = new Zillha_Avatar_Shortcode_Uploader();
		$this->filter              = new Zillha_Avatar_Filter();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_pending_files' ) );
		add_action( 'delete_user', array( $this, 'on_delete_user' ) );

		register_activation_hook( ZILLHA_AVATAR_FILE, array( __CLASS__, 'on_activation' ) );
		register_deactivation_hook( ZILLHA_AVATAR_FILE, array( __CLASS__, 'on_deactivation' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'zillha-avatar',
			false,
			dirname( ZILLHA_AVATAR_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register hooks for each subsystem and self-heal the cleanup cron.
	 *
	 * @return void
	 */
	public function init() {
		$this->settings->register_hooks();
		$this->ajax->register_hooks();
		$this->shortcode_generator->register_hooks();
		$this->shortcode_uploader->register_hooks();
		$this->filter->register_hooks();

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Activation: seed option row, schedule cleanup cron.
	 *
	 * @return void
	 */
	public static function on_activation() {
		if ( false === get_option( ZILLHA_AVATAR_OPTION_KEY ) ) {
			add_option(
				ZILLHA_AVATAR_OPTION_KEY,
				array(
					'webhook_url' => '',
				)
			);
		}

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Deactivation: drop the cron only. Settings and saved attachments survive.
	 *
	 * @return void
	 */
	public static function on_deactivation() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * Register and enqueue the shared front-end CSS/JS bundle. Idempotent —
	 * both shortcodes can call this safely on the same page render.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( ! wp_style_is( self::ASSET_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::ASSET_HANDLE,
				ZILLHA_AVATAR_URL . 'assets/css/zillha-avatar.css',
				array(),
				ZILLHA_AVATAR_VERSION
			);
		}

		if ( ! wp_script_is( self::ASSET_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::ASSET_HANDLE,
				ZILLHA_AVATAR_URL . 'assets/js/zillha-avatar.js',
				array(),
				ZILLHA_AVATAR_VERSION,
				true
			);

			wp_localize_script(
				self::ASSET_HANDLE,
				'ZillhaAvatarConfig',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( Zillha_Avatar_Ajax::NONCE_ACTION ),
					'i18n'    => array(
						'generating'     => __( 'Generating avatar… this can take up to two minutes.', 'zillha-avatar' ),
						'saving'         => __( 'Saving as profile picture…', 'zillha-avatar' ),
						'uploading'      => __( 'Uploading…', 'zillha-avatar' ),
						'removing'       => __( 'Removing…', 'zillha-avatar' ),
						'genericError'   => __( 'Something went wrong. Please try again.', 'zillha-avatar' ),
						'networkError'   => __( 'Network error. Please try again.', 'zillha-avatar' ),
						'savedSuccess'   => __( 'Saved! Your profile picture has been updated.', 'zillha-avatar' ),
						'uploadedOk'     => __( 'Avatar updated.', 'zillha-avatar' ),
						'removedOk'      => __( 'Custom avatar removed.', 'zillha-avatar' ),
						'downloadName'   => __( 'zillha-avatar.webp', 'zillha-avatar' ),
						'sessionExpired' => __( 'Your avatar session expired. Please generate the avatar again.', 'zillha-avatar' ),
						'fillRequired'   => __( 'Please fill in all required fields.', 'zillha-avatar' ),
						'pickImage'      => __( 'Please choose an image first.', 'zillha-avatar' ),
						'remove'         => __( 'Remove custom avatar', 'zillha-avatar' ),
					),
				)
			);
		}

		wp_enqueue_style( self::ASSET_HANDLE );
		wp_enqueue_script( self::ASSET_HANDLE );
	}

	/**
	 * Read the configured webhook URL.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		$options = get_option( ZILLHA_AVATAR_OPTION_KEY, array() );
		if ( ! is_array( $options ) || empty( $options['webhook_url'] ) ) {
			return '';
		}
		return (string) $options['webhook_url'];
	}

	/**
	 * Absolute path to the directory holding pending generated avatar binaries.
	 *
	 * @return string Empty when the uploads dir cannot be resolved.
	 */
	public static function pending_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		return trailingslashit( $uploads['basedir'] ) . ZILLHA_AVATAR_PENDING_DIR_NAME;
	}

	/**
	 * Mint a fresh 32-character alphanumeric pending token.
	 *
	 * @return string
	 */
	public static function generate_pending_token() {
		return wp_generate_password( ZILLHA_AVATAR_PENDING_TOKEN_LENGTH, false );
	}

	/**
	 * Validate token shape before letting it touch the filesystem.
	 *
	 * @param mixed $token Candidate token.
	 * @return bool
	 */
	public static function is_valid_pending_token( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z0-9]{' . (int) ZILLHA_AVATAR_PENDING_TOKEN_LENGTH . '}$/', $token );
	}

	/**
	 * Build the absolute pending file path for a user/token pair.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Pending token.
	 * @return string Empty when the uploads dir or token is invalid.
	 */
	public static function pending_file_path( $user_id, $token ) {
		$dir = self::pending_dir();
		if ( '' === $dir || ! self::is_valid_pending_token( $token ) ) {
			return '';
		}
		return trailingslashit( $dir ) . absint( $user_id ) . '-' . $token . '.webp';
	}

	/**
	 * Resolve the pending path stored against a user, if any.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function pending_file_path_for_user( $user_id ) {
		$token = get_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY, true );
		if ( ! self::is_valid_pending_token( $token ) ) {
			return '';
		}
		return self::pending_file_path( $user_id, $token );
	}

	/**
	 * Lazily create the pending directory. The unguessable filename is the
	 * security boundary; we do not write `.htaccess` here.
	 *
	 * @return bool
	 */
	public static function ensure_pending_dir() {
		$dir = self::pending_dir();
		if ( '' === $dir ) {
			return false;
		}
		return (bool) wp_mkdir_p( $dir );
	}

	/**
	 * WP-Cron callback: delete pending files older than the TTL.
	 *
	 * Streams via DirectoryIterator to keep memory flat on busy installs.
	 *
	 * @return void
	 */
	public static function cleanup_pending_files() {
		$dir = self::pending_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$cutoff = time() - ZILLHA_AVATAR_PENDING_TTL;

		try {
			$iter = new DirectoryIterator( $dir );
		} catch ( Exception $e ) {
			return;
		}

		foreach ( $iter as $entry ) {
			if ( $entry->isDot() || ! $entry->isFile() ) {
				continue;
			}
			if ( 'webp' !== strtolower( $entry->getExtension() ) ) {
				continue;
			}
			if ( $entry->getMTime() < $cutoff ) {
				wp_delete_file( $entry->getPathname() );
			}
		}
	}

	/**
	 * Delete the saved attachment and pending file when a user is removed.
	 *
	 * @param int $user_id User being deleted.
	 * @return void
	 */
	public function on_delete_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		$attachment_id = (int) get_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, true );
		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
		delete_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY );

		$pending = self::pending_file_path_for_user( $user_id );
		if ( '' !== $pending && file_exists( $pending ) ) {
			wp_delete_file( $pending );
		}
		delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY );
	}
}

Zillha_Avatar_Plugin::instance();
