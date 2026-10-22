<?php
/**
 * Rug reviews (routes/reviews.py + components/RugReviews.tsx), stored as
 * WooCommerce product reviews — so moderation is WooCommerce's own
 * Products → Reviews screen (approve / spam / trash), the star rating is
 * WooCommerce's `rating` meta and "verified buyer" its `verified` meta.
 * The title lives in `drc_title`.
 *
 * Rules carried over from the original:
 *  - every new review is held for approval;
 *  - one review per email per rug (a rejected/trashed one doesn't count);
 *  - verified = a logged-in customer who bought this rug;
 *  - the vendor is emailed (vendor_new_review template);
 *  - the public summary shows the newest 100 approved reviews.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Reviews {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'dreamrugs/v1', '/customer/products/(?P<id>\d+)/reviews', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'summary_response' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	/** {average_rating, review_count, rating_counts, reviews[]} for a rug. */
	public static function summary( int $product_id ): array {
		$comments = get_comments( array(
			'post_id' => $product_id,
			'status'  => 'approve',
			'type'    => 'review',
			'number'  => 100,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		) );
		$counts  = array( '1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0 );
		$reviews = array();
		$total   = 0;
		foreach ( $comments as $c ) {
			$rating = (int) get_comment_meta( $c->comment_ID, 'rating', true );
			if ( $rating < 1 || $rating > 5 ) {
				continue;
			}
			++$counts[ (string) $rating ];
			$total    += $rating;
			$reviews[] = array(
				'id'                => (int) $c->comment_ID,
				'name'              => $c->comment_author,
				'rating'            => $rating,
				'title'             => (string) get_comment_meta( $c->comment_ID, 'drc_title', true ) ?: null,
				'body'              => $c->comment_content,
				'is_verified_buyer' => (bool) get_comment_meta( $c->comment_ID, 'verified', true ),
				'created_at'        => mysql_to_rfc3339( $c->comment_date_gmt ),
			);
		}
		$n = count( $reviews );
		return array(
			'average_rating' => $n ? round( $total / $n, 2 ) : null,
			'review_count'   => $n,
			'rating_counts'  => $counts,
			'reviews'        => $reviews,
		);
	}

	public static function summary_response( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			return new WP_Error( 'drc_not_found', 'Rug not found.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( self::summary( $product->get_id() ), 200 );
	}

	public static function submit( WP_REST_Request $request ) {
		if ( class_exists( 'DRC_Security' ) ) {
			$blocked = DRC_Security::guard( $request, 'rug_review', array( array( 3, HOUR_IN_SECONDS ), array( 10, DAY_IN_SECONDS ) ) );
			if ( is_wp_error( $blocked ) ) {
				return $blocked;
			}
		}
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'drc_not_found', 'Rug not found.', array( 'status' => 404 ) );
		}

		$user   = wp_get_current_user();
		$name   = mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 150 );
		$email  = $user->ID ? $user->user_email : sanitize_email( (string) $request->get_param( 'email' ) );
		$rating = (int) $request->get_param( 'rating' );
		$title  = mb_substr( sanitize_text_field( (string) $request->get_param( 'title' ) ), 0, 150 );
		$body   = mb_substr( sanitize_textarea_field( (string) $request->get_param( 'body' ) ), 0, 3000 );

		if ( '' === $name && $user->ID ) {
			$name = $user->display_name;
		}
		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'drc_invalid', 'Please enter your name and a valid email.', array( 'status' => 422 ) );
		}
		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'drc_invalid', 'Please choose a star rating.', array( 'status' => 422 ) );
		}
		if ( mb_strlen( trim( $body ) ) < 10 ) {
			return new WP_Error( 'drc_invalid', 'Please write at least a sentence about the rug.', array( 'status' => 422 ) );
		}

		$existing = get_comments( array(
			'post_id'      => $product->get_id(),
			'type'         => 'review',
			'author_email' => $email,
			'status'       => array( 'approve', 'hold' ),
			'count'        => true,
		) );
		if ( $existing ) {
			return new WP_Error( 'drc_duplicate', "You've already reviewed this rug — thank you!", array( 'status' => 409 ) );
		}

		$verified = $user->ID && function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( $email, $user->ID, $product->get_id() );

		$comment_id = wp_insert_comment( array(
			'comment_post_ID'      => $product->get_id(),
			'comment_author'       => $name,
			'comment_author_email' => $email,
			'comment_author_IP'    => class_exists( 'DRC_Security' ) ? DRC_Security::client_ip() : '',
			'comment_content'      => $body,
			'comment_type'         => 'review',
			'comment_approved'     => 0,
			'user_id'              => $user->ID,
			'comment_meta'         => array(
				'rating'    => $rating,
				'verified'  => $verified ? 1 : 0,
				'drc_title' => $title,
			),
		) );
		if ( ! $comment_id ) {
			return new WP_Error( 'drc_insert_failed', 'We could not send your review. Please try again.', array( 'status' => 500 ) );
		}

		DRC_Email_Templates::send( 'vendor_new_review', DRC_Email_Templates::vendor_recipient(), array(
			'customer_name'  => $name,
			'customer_email' => $email,
			'rug_name'       => $product->get_name(),
			'rating'         => $rating,
			'verified'       => $verified ? 'Yes' : 'No',
			'title'          => $title ?: '—',
			'body'           => $body,
		), array(), array( 'Reply-To: ' . $email ) );

		return new WP_REST_Response( array( 'message' => 'Thank you! Your review will appear once it has been approved.' ), 201 );
	}
}
