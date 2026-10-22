/**
 * "Story Details" meta box on the Design Story edit screen
 * (DRC_Design_Stories::render_meta_box) — the ordered photos/videos
 * repeater, ported from RugStories.tsx's media list editor. Rows come from
 * a server-rendered <template>; wp.media() picks files from the media
 * library (same pattern as DRC_Color_Options' swatch-image picker). Row
 * order in the DOM is the saved order. All validation happens again
 * server-side in DRC_Design_Stories::save().
 */
( function () {
	var table = document.getElementById( 'drc-story-media-table' );
	if ( ! table || ! window.wp || ! wp.media ) {
		return;
	}
	var tbody = document.getElementById( 'drc-story-media-rows' );
	var template = document.getElementById( 'drc-story-media-row-template' );
	var max = parseInt( table.getAttribute( 'data-max' ), 10 ) || 40;
	var nextIndex = tbody.querySelectorAll( 'tr' ).length;

	function setPreview( row, src ) {
		var img = row.querySelector( '[data-role="preview"]' );
		var empty = row.querySelector( '[data-role="preview-empty"]' );
		if ( src ) {
			img.src = src;
			img.style.display = '';
			empty.style.display = 'none';
		} else {
			img.removeAttribute( 'src' );
			img.style.display = 'none';
			empty.style.display = '';
		}
	}

	function thumbOf( attachment ) {
		var sizes = attachment.sizes || {};
		return ( sizes.thumbnail || sizes.medium || {} ).url || attachment.url;
	}

	function pick( title, type, multiple, onSelect ) {
		var frame = wp.media( { title: title, library: { type: type }, button: { text: 'Use selected' }, multiple: multiple } );
		frame.on( 'select', function () {
			onSelect( frame.state().get( 'selection' ).toJSON() );
		} );
		frame.open();
	}

	function applyAttachment( row, attachment ) {
		var idInput = row.querySelector( '[data-role="attachment-id"]' );
		var urlInput = row.querySelector( '[data-role="url"]' );
		idInput.value = attachment.id;
		urlInput.value = attachment.url;
		if ( 'image' === row.getAttribute( 'data-type' ) ) {
			setPreview( row, thumbOf( attachment ) );
		}
	}

	function wireRow( row ) {
		var type = row.getAttribute( 'data-type' );
		var urlInput = row.querySelector( '[data-role="url"]' );
		var idInput = row.querySelector( '[data-role="attachment-id"]' );

		row.querySelector( '[data-role="choose"]' ).addEventListener( 'click', function () {
			pick( 'video' === type ? 'Select Video' : 'Select Photo', type, false, function ( items ) {
				if ( items[0] ) applyAttachment( row, items[0] );
			} );
		} );

		// Editing the URL by hand detaches the library file (an external
		// https:// link is then saved instead).
		urlInput.addEventListener( 'input', function () {
			idInput.value = '0';
		} );
		urlInput.addEventListener( 'change', function () {
			if ( 'image' === type && '0' === idInput.value ) {
				setPreview( row, /^https:\/\//i.test( urlInput.value ) ? urlInput.value : '' );
			}
		} );

		if ( 'video' === type ) {
			var posterId = row.querySelector( '[data-role="poster-id"]' );
			var posterUrl = row.querySelector( '[data-role="poster-url"]' );
			row.querySelector( '[data-role="choose-poster"]' ).addEventListener( 'click', function () {
				pick( 'Select Poster Image', 'image', false, function ( items ) {
					if ( ! items[0] ) return;
					posterId.value = items[0].id;
					posterUrl.value = items[0].url;
					setPreview( row, thumbOf( items[0] ) );
				} );
			} );
			row.querySelector( '[data-role="remove-poster"]' ).addEventListener( 'click', function () {
				posterId.value = '0';
				posterUrl.value = '';
				setPreview( row, '' );
			} );
			posterUrl.addEventListener( 'input', function () {
				posterId.value = '0';
			} );
			posterUrl.addEventListener( 'change', function () {
				if ( '0' === posterId.value ) {
					setPreview( row, /^https:\/\//i.test( posterUrl.value ) ? posterUrl.value : '' );
				}
			} );
		}

		row.querySelector( '[data-role="up"]' ).addEventListener( 'click', function () {
			if ( row.previousElementSibling ) tbody.insertBefore( row, row.previousElementSibling );
		} );
		row.querySelector( '[data-role="down"]' ).addEventListener( 'click', function () {
			if ( row.nextElementSibling ) tbody.insertBefore( row.nextElementSibling, row );
		} );
		row.querySelector( '[data-role="remove"]' ).addEventListener( 'click', function () {
			row.remove();
		} );
	}

	function addRow( type ) {
		if ( tbody.querySelectorAll( 'tr' ).length >= max ) {
			window.alert( 'A story can have at most ' + max + ' photos and videos.' );
			return null;
		}
		var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex++ ) ).replace( /__TYPE__/g, type );
		var wrapper = document.createElement( 'tbody' );
		wrapper.innerHTML = html;
		var row = wrapper.querySelector( 'tr' );
		row.querySelector( '[data-role="type-label"]' ).textContent = 'video' === type ? 'Video' : 'Photo';
		row.querySelector( '[data-role="poster-field"]' ).style.display = 'video' === type ? '' : 'none';
		row.querySelector( '[data-role="preview-empty"]' ).className = 'dashicons ' + ( 'video' === type ? 'dashicons-video-alt3' : 'dashicons-format-image' );
		tbody.appendChild( row );
		wireRow( row );
		return row;
	}

	tbody.querySelectorAll( 'tr' ).forEach( wireRow );

	document.getElementById( 'drc-story-add-images' ).addEventListener( 'click', function () {
		pick( 'Add Photos', 'image', true, function ( items ) {
			items.forEach( function ( attachment ) {
				var row = addRow( 'image' );
				if ( row ) applyAttachment( row, attachment );
			} );
		} );
	} );
	document.getElementById( 'drc-story-add-video' ).addEventListener( 'click', function () {
		pick( 'Add Video', 'video', false, function ( items ) {
			if ( ! items[0] ) return;
			var row = addRow( 'video' );
			if ( row ) applyAttachment( row, items[0] );
		} );
	} );
} )();
