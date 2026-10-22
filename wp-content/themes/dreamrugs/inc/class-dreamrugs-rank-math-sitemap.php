<?php
/** Rank Math sitemap provider for dreamrugs_sitemap_extra_urls() — served as drc-pages-sitemap.xml. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dreamrugs_Rank_Math_Sitemap implements \RankMath\Sitemap\Providers\Provider {

	const TYPE = 'drc-pages';

	public function handles_type( $type ) {
		return self::TYPE === $type;
	}

	public function get_index_links( $max_entries ) {
		$pages = (int) ceil( count( dreamrugs_sitemap_extra_urls() ) / max( 1, (int) $max_entries ) );
		$links = array();
		for ( $i = 1; $i <= $pages; $i++ ) {
			$links[] = array(
				'loc'     => \RankMath\Sitemap\Router::get_base_url( self::TYPE . '-sitemap' . ( $i > 1 ? $i : '' ) . '.xml' ),
				'lastmod' => '',
			);
		}
		return $links;
	}

	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		$urls = array_slice( dreamrugs_sitemap_extra_urls(), ( max( 1, (int) $current_page ) - 1 ) * $max_entries, $max_entries );
		return array_map( function ( $url ) {
			return array( 'loc' => $url, 'mod' => '' );
		}, $urls );
	}
}
