<?php
/**
 * Ported from frontend/src/pages/RugServices.tsx, page: 'tracking' —
 * RugServices.tsx itself has no 'tracking' branch at all; the entire visible
 * content of /order-tracking is CustomerLayout.tsx's global
 * <OrderTrackingSection standalone /> component. This template renders that
 * same shared section (template-parts/order-tracking-section.php) with
 * $standalone = true (h1 heading); footer.php renders the condensed
 * $standalone = false teaser version of the same section on every other
 * page, matching the original's always-mounted layout component exactly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
$standalone = true;
require get_theme_file_path( 'template-parts/order-tracking-section.php' );
get_footer();
