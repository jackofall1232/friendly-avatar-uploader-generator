<?php
/**
 * Avatar URL filter — replaces Gravatar URLs with the saved attachment.
 *
 * @package Zillha_Avatar
 */

defined( 'ABSPATH' ) || exit;

/**
 * The single get_avatar_url filter for the plugin. Registered at priority
 * 1 so we win against any other plugin filtering at the default 10.
 */
class Zillha_Avatar_Filter {

	/**
	 * Wire the avatar URL filter.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'get_avatar_url', array( $this, 'filter_avatar_url' ), 1, 3 );
	}

	/**
	 * Replace the avatar URL with the user's saved attachment when one is set.
	 *
	 * @param string $url         Default avatar URL.
	 * @param mixed  $id_or_email Object identifying the user. May be an int,
	 *                            email string, WP_User, WP_Post, or WP_Comment.
	 * @param array  $args        Avatar args (size, default, etc.).
	 * @return string
	 */
	public function filter_avatar_url( $url, $id_or_email, $args = array() ) {
		$user_id = $this->resolve_user_id( $id_or_email );
		if ( ! $user_id ) {
			return $url;
		}

		$attachment_id = (int) get_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, true );
		if ( $attachment_id <= 0 ) {
			return $url;
		}

		// A previously saved attachment may have been deleted from the
		// media library. Fall back to the default URL gracefully.
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return $url;
		}

		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$size = max( 1, min( 2048, $size ) );

		$image = wp_get_attachment_image_src( $attachment_id, array( $size, $size ) );
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			return $image[0];
		}

		$full = wp_get_attachment_url( $attachment_id );
		return $full ? $full : $url;
	}

	/**
	 * Resolve the user from any of the shapes WordPress passes to the
	 * avatar filter.
	 *
	 * @param mixed $id_or_email int | string email | WP_User | WP_Post | WP_Comment | object.
	 * @return int 0 when no user can be resolved.
	 */
	private function resolve_user_id( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( is_string( $id_or_email ) ) {
			if ( ! is_email( $id_or_email ) ) {
				return 0;
			}
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
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

		if ( is_object( $id_or_email ) ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			if ( ! empty( $id_or_email->ID ) ) {
				return (int) $id_or_email->ID;
			}
			if ( ! empty( $id_or_email->comment_author_email ) ) {
				$user = get_user_by( 'email', $id_or_email->comment_author_email );
				return $user ? (int) $user->ID : 0;
			}
		}

		return 0;
	}
}
