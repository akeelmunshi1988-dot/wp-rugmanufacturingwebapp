<?php
/** WordPress core sitemap provider for dreamrugs_sitemap_extra_urls(). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dreamrugs_Sitemap_Provider extends WP_Sitemaps_Provider {

	public function __construct() {
		$this->name        = 'drcpages';
		$this->object_type = 'drcpages';
	}

	public function get_url_list( $page_num, $object_subtype = '' ) {
		$per  = wp_sitemaps_get_max_urls( $this->object_type );
		$urls = array_slice( dreamrugs_sitemap_extra_urls(), ( $page_num - 1 ) * $per, $per );
		return array_map( function ( $url ) {
			return array( 'loc' => $url );
		}, $urls );
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		return (int) ceil( count( dreamrugs_sitemap_extra_urls() ) / wp_sitemaps_get_max_urls( $this->object_type ) );
	}
}
