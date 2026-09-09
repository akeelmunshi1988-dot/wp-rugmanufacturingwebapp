<?php
/**
 * Routes /project-gallery (listing) and /project-gallery/{id} (detail) to
 * theme templates — ported from frontend/src/pages/CustomerProjectGallery.tsx
 * and ProjectDetail.tsx. drc_gallery_item is `public => false`
 * (class-content-types.php), so — like weave types (class-weave-routes.php)
 * — these need their own rewrite rules rather than riding WordPress's normal
 * single/archive template hierarchy for the CPT.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Project_Gallery_Routes {

	const QUERY_VAR = 'drc_project_gallery_id';
	const LIST_QUERY_VAR = 'drc_project_gallery_list';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_filter( 'template_include', array( __CLASS__, 'maybe_load_template' ) );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^project-gallery/([0-9]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^project-gallery/?$', 'index.php?' . self::LIST_QUERY_VAR . '=1', 'top' );
	}

	public static function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::LIST_QUERY_VAR;
		return $vars;
	}

	public static function maybe_load_template( string $template ): string {
		if ( get_query_var( self::QUERY_VAR ) ) {
			$theme_template = locate_template( 'page-templates/project-gallery-detail.php' );
			return $theme_template ?: $template;
		}
		if ( get_query_var( self::LIST_QUERY_VAR ) ) {
			$theme_template = locate_template( 'page-templates/project-gallery.php' );
			return $theme_template ?: $template;
		}
		return $template;
	}
}
