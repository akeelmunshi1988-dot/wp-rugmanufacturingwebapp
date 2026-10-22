/**
 * Catalog page: infinite scroll and colour-swatch previews, ported from
 * CustomerCatalog.tsx. The server renders page 1 plus normal page links;
 * with JS, the links hide and the next page's cards are fetched and
 * appended when the end of the grid scrolls into view.
 */
( function () {
	function wireSwatches( scope ) {
		scope.querySelectorAll( '[data-drc-swatch]' ).forEach( function ( sw ) {
			if ( sw.dataset.drcWired ) return;
			sw.dataset.drcWired = '1';
			var card = sw.closest( 'a' );
			var img = card && card.querySelector( '[data-drc-cover]' );
			if ( ! img ) return;
			var show = function ( e ) { e.preventDefault(); img.src = sw.dataset.drcSwatch; };
			sw.addEventListener( 'mouseenter', show );
			sw.addEventListener( 'focus', show );
			sw.addEventListener( 'click', show );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var grid = document.getElementById( 'drc-catalog-grid' );
		var sentinel = document.getElementById( 'drc-catalog-sentinel' );
		if ( ! grid ) return;
		wireSwatches( grid );
		if ( ! sentinel || ! ( 'IntersectionObserver' in window ) ) return;

		var pager = document.getElementById( 'drc-catalog-pager' );
		if ( pager ) pager.style.display = 'none';
		var loading = false;

		var observer = new IntersectionObserver( function ( entries ) {
			if ( ! entries[0].isIntersecting || loading || ! sentinel.dataset.next ) return;
			loading = true;
			sentinel.querySelector( '.drc-loading' ).classList.remove( 'hidden' );
			fetch( sentinel.dataset.next, { credentials: 'same-origin' } )
				.then( function ( r ) { return r.text(); } )
				.then( function ( html ) {
					var doc = new DOMParser().parseFromString( html, 'text/html' );
					var nextGrid = doc.getElementById( 'drc-catalog-grid' );
					var nextSentinel = doc.getElementById( 'drc-catalog-sentinel' );
					if ( nextGrid ) {
						Array.from( nextGrid.children ).forEach( function ( card ) { grid.appendChild( document.importNode( card, true ) ); } );
						wireSwatches( grid );
						if ( window.drcCurrency ) window.drcCurrency.reapply();
					}
					sentinel.dataset.next = nextSentinel ? nextSentinel.dataset.next : '';
				} )
				.catch( function () { sentinel.dataset.next = ''; if ( pager ) pager.style.display = ''; } )
				.finally( function () {
					loading = false;
					sentinel.querySelector( '.drc-loading' ).classList.add( 'hidden' );
					if ( ! sentinel.dataset.next ) sentinel.querySelector( '.drc-end' ).classList.remove( 'hidden' );
				} );
		}, { rootMargin: '400px 0px' } );
		observer.observe( sentinel );
	} );
} )();
