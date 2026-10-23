<?php
/**
 * WebP for uploaded images — WordPress counterpart of the original app's
 * backend/optimize_images.py + nginx WebP negotiation (DEPLOYMENT.md,
 * "WebP image copies"). Uploads are often 2–4 MB PNG/JPEG; with this on,
 * WordPress writes the "full" image and every resized copy (large / medium /
 * thumbnail / WooCommerce sizes) as WebP at quality 82 — the same quality
 * the original app uses. The uploaded JPEG/PNG stays on disk as the
 * attachment's original image (wp_get_original_image_url()), which is what
 * the Pinterest feed links to.
 *
 * Applies to new uploads automatically. Existing images need their sizes
 * regenerated once: `wp media regenerate --yes` (see README).
 *
 * No server config needed, unlike the original's nginx rule — works the
 * same under Apache (local Docker) and nginx.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Webp_Images {

	const QUALITY = 82;

	public static function init() {
		add_filter( 'image_editor_output_format', array( __CLASS__, 'output_format' ) );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'quality' ), 10, 2 );
	}

	/**
	 * Resized copies of JPEG/PNG uploads are saved as WebP — only when the
	 * server's image library can actually write WebP, so a host without it
	 * keeps producing JPEG/PNG rather than failing uploads.
	 */
	public static function output_format( array $formats ): array {
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return $formats;
		}
		$formats['image/jpeg'] = 'image/webp';
		$formats['image/png']  = 'image/webp';
		return $formats;
	}

	public static function quality( $quality, $mime_type ) {
		return 'image/webp' === $mime_type ? self::QUALITY : $quality;
	}
}
