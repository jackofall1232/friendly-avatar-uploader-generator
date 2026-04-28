<?php
/**
 * Filters core avatar resolution to use the custom uploaded avatar.
 *
 * @package FriendlyAvatarUploader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class FAU_Avatar_Filter
 *
 * Hooks into pre_get_avatar_data so that any call to get_avatar() or
 * get_avatar_url() returns the user's uploaded avatar when one exists.
 */
class FAU_Avatar_Filter {

	/**
	 * Constructor: register the filter.
	 */
	public function __construct() {
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );
	}

	/**
	 * Resolve the user from the supplied identifier and swap in the custom avatar URL.
	 *
	 * @param array $args        Avatar args produced by core.
	 * @param mixed $id_or_email Identifier passed to get_avatar().
	 * @return array
	 */
	public function filter_avatar_data( $args, $id_or_email ) {
		$user_id = $this->resolve_user_id( $id_or_email );

		if ( ! $user_id ) {
			return $args;
		}

		$custom_url = get_user_meta( $user_id, FAU_META_KEY, true );

		if ( ! empty( $custom_url ) ) {
			$args['url']           = $custom_url;
			$args['found_avatar']  = true;
		}

		return $args;
	}

	/**
	 * Resolve a user ID from the various identifier shapes WordPress accepts.
	 *
	 * @param mixed $id_or_email int | WP_User | WP_Post | WP_Comment | string email.
	 * @return int 0 when no user could be resolved.
	 */
	protected function resolve_user_id( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}

		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}

		if ( $id_or_email instanceof WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}

			if ( ! empty( $id_or_email->comment_author_email ) ) {
				$user = get_user_by( 'email', $id_or_email->comment_author_email );
				return $user ? (int) $user->ID : 0;
			}

			return 0;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}

		return 0;
	}
}
