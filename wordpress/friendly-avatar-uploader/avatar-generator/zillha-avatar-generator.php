<?php
/**
 * Plugin Name:       ZillHa Avatar Generator
 * Plugin URI:        https://zillha.games/
 * Description:       Generate AI avatars from a questionnaire by calling an n8n webhook, then download or set as your WordPress profile picture.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ZillHa Games
 * Author URI:        https://zillha.games/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zillha-avatar-generator
 * Domain Path:       /languages
 *
 * @package ZillHa_Avatar_Generator
 */

defined( 'ABSPATH' ) || exit;

define( 'ZILLHA_AVATAR_GENERATOR_VERSION', '1.0.1' );
define( 'ZILLHA_AVATAR_GENERATOR_FILE', __FILE__ );
define( 'ZILLHA_AVATAR_GENERATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZILLHA_AVATAR_GENERATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'ZILLHA_AVATAR_GENERATOR_BASENAME', plugin_basename( __FILE__ ) );

define( 'ZILLHA_AVATAR_OPTION_KEY', 'zillha_avatar_generator_options' );
define( 'ZILLHA_AVATAR_USER_META_KEY', 'zillha_avatar_attachment_id' );
define( 'ZILLHA_AVATAR_PENDING_META_KEY', 'zillha_avatar_pending_token' );
define( 'ZILLHA_AVATAR_PENDING_DIR_NAME', 'zillha-pending' );
define( 'ZILLHA_AVATAR_PENDING_TTL', 10 * MINUTE_IN_SECONDS );
define( 'ZILLHA_AVATAR_PENDING_TOKEN_LENGTH', 32 );
define( 'ZILLHA_AVATAR_WEBHOOK_TIMEOUT', 120 );

require_once ZILLHA_AVATAR_GENERATOR_DIR . 'includes/class-zillha-avatar-settings.php';
require_once ZILLHA_AVATAR_GENERATOR_DIR . 'includes/class-zillha-avatar-ajax.php';
require_once ZILLHA_AVATAR_GENERATOR_DIR . 'includes/class-zillha-avatar-shortcode.php';

/**
 * Main plugin bootstrapper.
 */
final class Zillha_Avatar_Generator {

	/**
	 * WP-Cron hook used to purge stale pending-avatar files.
	 */
	const CLEANUP_HOOK = 'zillha_avatar_pending_cleanup';

	/**
	 * Singleton instance.
	 *
	 * @var Zillha_Avatar_Generator|null
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
	 * Shortcode handler.
	 *
	 * @var Zillha_Avatar_Shortcode
	 */
	public $shortcode;

	/**
	 * Get singleton instance.
	 *
	 * @return Zillha_Avatar_Generator
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings  = new Zillha_Avatar_Settings();
		$this->ajax      = new Zillha_Avatar_Ajax();
		$this->shortcode = new Zillha_Avatar_Shortcode();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ) );

		register_activation_hook( ZILLHA_AVATAR_GENERATOR_FILE, array( __CLASS__, 'on_activation' ) );
		register_deactivation_hook( ZILLHA_AVATAR_GENERATOR_FILE, array( __CLASS__, 'on_deactivation' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'zillha-avatar-generator',
			false,
			dirname( ZILLHA_AVATAR_GENERATOR_BASENAME ) . '/languages'
		);
	}

	/**
	 * Boot hooks once WordPress is ready.
	 *
	 * @return void
	 */
	public function init() {
		$this->settings->register_hooks();
		$this->ajax->register_hooks();
		$this->shortcode->register_hooks();

		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_pending_files' ) );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Activation hook: ensure the option row exists and the cleanup cron is scheduled.
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
	 * Deactivation hook: clear the cleanup cron. Settings and user meta survive.
	 *
	 * @return void
	 */
	public static function on_deactivation() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * Retrieve the configured webhook URL.
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
	 * Absolute path to the directory holding pending avatar binaries.
	 *
	 * Returns an empty string if WordPress could not resolve the uploads dir.
	 *
	 * @return string
	 */
	public static function pending_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		return trailingslashit( $uploads['basedir'] ) . ZILLHA_AVATAR_PENDING_DIR_NAME;
	}

	/**
	 * Generate a fresh pending-avatar token. Alphanumeric only, filesystem-safe.
	 *
	 * @return string
	 */
	public static function generate_pending_token() {
		return wp_generate_password( ZILLHA_AVATAR_PENDING_TOKEN_LENGTH, false );
	}

	/**
	 * Validate a token shape before letting it touch the filesystem.
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
	 * Absolute path to a pending avatar file for the given user + token.
	 *
	 * Token is required and validated; predictable {user_id}.webp paths are gone.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $token   Token previously returned by generate_pending_token().
	 * @return string Empty string when the uploads dir or token is unusable.
	 */
	public static function pending_file_path( $user_id, $token ) {
		$dir = self::pending_dir();
		if ( '' === $dir || ! self::is_valid_pending_token( $token ) ) {
			return '';
		}
		return trailingslashit( $dir ) . absint( $user_id ) . '-' . $token . '.webp';
	}

	/**
	 * Look up the saved pending path for a user, if any.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Empty when no pending token is stored or path is unresolvable.
	 */
	public static function pending_file_path_for_user( $user_id ) {
		$token = get_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY, true );
		if ( ! self::is_valid_pending_token( $token ) ) {
			return '';
		}
		return self::pending_file_path( $user_id, $token );
	}

	/**
	 * Ensure the pending dir exists. The directory protection lives in the
	 * filename: the {user_id}-{token}.webp form is unguessable for the TTL
	 * window even if the directory is web-served.
	 *
	 * @return bool True on success.
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
	 * Streams entries via DirectoryIterator to avoid loading every filename
	 * into memory at once on busy installs.
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
				@unlink( $entry->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}

Zillha_Avatar_Generator::instance();
