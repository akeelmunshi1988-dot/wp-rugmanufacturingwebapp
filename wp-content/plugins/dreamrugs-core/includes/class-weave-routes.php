<?php
/**
 * Routes /weaves/{slug} to the theme's page-templates/weave-story.php —
 * ported from frontend/src/pages/WeaveTypePage.tsx's `/weaves/:weave` route.
 * Weave type is plain postmeta (_drc_weave_type, see
 * class-catalog-taxonomies.php's header comment on why pile/weave have no
 * taxonomy of their own), so this can't ride WordPress's taxonomy-archive
 * template hierarchy the way material/space/mood do — it needs its own
 * rewrite rule + query var instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Weave_Routes {

	const QUERY_VAR = 'drc_weave';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'template_include', array( __CLASS__, 'maybe_load_template' ) );
	}

	public static function add_rewrite_rule() {
		add_rewrite_rule( '^weaves/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_load_template( string $template ): string {
		$weave = get_query_var( self::QUERY_VAR );
		if ( '' === $weave || null === $weave ) {
			return $template;
		}
		$theme_template = locate_template( 'page-templates/weave-story.php' );
		return $theme_template ?: $template;
	}
}
