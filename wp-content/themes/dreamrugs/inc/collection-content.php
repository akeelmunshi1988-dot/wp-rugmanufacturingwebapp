<?php
/**
 * Static craft/lifestyle story copy for the weave-type pages (/weaves/{slug})
 * and the material/space/mood taxonomy-archive story blocks — a direct port
 * of frontend/src/pages/WeaveTypePage.tsx's WEAVES/COLLECTIONS maps and its
 * generic fallback logic for any slug/value not in those maps. This is
 * static reference/editorial copy, not vendor data, so (like WeaveTypePage.tsx
 * itself) it lives as a constant map here rather than a settings-screen
 * field — see the ThemeForest-prep plan's Phase A2 note on this choice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array<string, array{name:string, eyebrow:string, intro:string, story:string, bestFor:string, character:string, making:string, care:string}> */
function dreamrugs_weave_story_map(): array {
	return array(
		'hand-knotted' => array(
			'name'      => 'Hand Knotted',
			'eyebrow'   => 'The pinnacle of rug craftsmanship',
			'intro'     => 'Every knot is tied individually by hand, creating heirloom rugs with exceptional definition, character, and longevity.',
			'story'     => 'A hand-knotted rug begins on a vertical loom, where an artisan ties thousands of individual knots around the foundation threads. The density and precision of those knots allow intricate patterns, nuanced colour transitions, and a surface that becomes more beautiful with age.',
			'bestFor'   => 'Living rooms, dining rooms, formal spaces, and collectors seeking a lasting investment.',
			'character' => 'Detailed, durable and one of a kind',
			'making'    => 'Several months',
			'care'      => 'Vacuum gently and rotate periodically',
		),
		'hand-tufted' => array(
			'name'      => 'Hand Tufted',
			'eyebrow'   => 'Plush texture, shaped by hand',
			'intro'     => 'Yarn is placed into a stretched canvas by hand to build a soft, expressive pile with excellent design flexibility.',
			'story'     => 'Using a handheld tufting tool, artisans guide yarn through a canvas foundation before securing the reverse with a backing. This technique creates a dense, comfortable surface and makes bold shapes, sculpted details, and custom colour stories possible in less time than hand knotting.',
			'bestFor'   => 'Bedrooms, lounges, family rooms, and interiors that call for softness underfoot.',
			'character' => 'Plush, versatile and richly textured',
			'making'    => 'Several weeks',
			'care'      => 'Vacuum without a beater bar',
		),
		'flatweave' => array(
			'name'      => 'Flatweave',
			'eyebrow'   => 'Lightweight, practical and reversible',
			'intro'     => 'Warp and weft threads are interlaced without a pile, producing a slim, durable rug with a crisp graphic quality.',
			'story'     => 'Flatweaves are made by passing horizontal weft yarns over and under vertical warp yarns. With no raised pile, they are easy to move, layer, and maintain. Their low profile works especially well beneath doors and dining furniture while showing geometric patterns with clarity.',
			'bestFor'   => 'Dining rooms, entryways, kitchens, layered rooms, and other high-traffic spaces.',
			'character' => 'Light, crisp and often reversible',
			'making'    => 'Two to four weeks',
			'care'      => 'Vacuum regularly and use a rug pad',
		),
		'machine-woven' => array(
			'name'      => 'Machine Woven',
			'eyebrow'   => 'Reliable performance for everyday rooms',
			'intro'     => 'Precision looms create consistent patterns and resilient surfaces that balance design, practicality, and accessible pricing.',
			'story'     => 'Computer-guided looms weave yarn at speed with even tension and repeatable detail. The construction is particularly suited to easy-care fibres and dependable everyday rugs, offering a broad range of styles with shorter production times.',
			'bestFor'   => 'Busy households, outdoor rooms, children\'s spaces, rentals, and high-traffic areas.',
			'character' => 'Consistent, resilient and easy-care',
			'making'    => 'Days to a few weeks',
			'care'      => 'Follow the fibre-specific care label',
		),
	);
}

/** @return array<string, array{name:string, eyebrow:string, intro:string, story:string, bestFor:string, character:string, making:string, care:string}> keyed "facet/value" */
function dreamrugs_collection_story_map(): array {
	return array(
		'space/living_room' => array(
			'name' => 'Living Room', 'eyebrow' => 'The foundation of a room made for gathering',
			'intro' => 'Living-room rugs anchor furniture, soften acoustics, and bring the colours of your most-used space together.',
			'story' => 'The right living-room rug establishes the scale and mood of the entire room. A generous size lets the front legs of sofas and chairs sit comfortably on the rug, connecting separate pieces into one inviting conversation area while adding warmth and texture underfoot.',
			'bestFor' => 'Conversation areas, family rooms, open-plan seating, and formal lounges.', 'character' => 'Grounded, welcoming and expressive', 'making' => 'Sized around your seating plan', 'care' => 'Rotate regularly for even wear',
		),
		'space/bedroom' => array(
			'name' => 'Bedroom', 'eyebrow' => 'A softer beginning and end to every day',
			'intro' => 'Bedroom rugs introduce quiet colour, warmth, and a comfortable landing beside and beneath the bed.',
			'story' => 'A bedroom rug should feel calm and generous. Placed beneath the bed or as runners along either side, it frames the room\'s focal point and gives bare feet a soft surface. Low-contrast patterns and tactile natural fibres support a restful atmosphere.',
			'bestFor' => 'Primary bedrooms, guest rooms, children\'s rooms, and bedside runners.', 'character' => 'Soft, restful and intimate', 'making' => 'Custom-sized to your bed', 'care' => 'Vacuum gently and rotate seasonally',
		),
		'space/dining_room' => array(
			'name' => 'Dining Room', 'eyebrow' => 'Designed to frame every shared meal',
			'intro' => 'Dining rugs define the table area and add comfort while standing up to moving chairs and everyday entertaining.',
			'story' => 'For a balanced dining room, the rug should extend far enough beyond the table for chairs to remain on it when pulled out. Durable, lower-pile constructions make movement easy and help the dining setting read as one composed centrepiece.',
			'bestFor' => 'Dining tables, breakfast areas, open-plan kitchens, and entertaining spaces.', 'character' => 'Structured, durable and convivial', 'making' => 'Proportioned to table and chairs', 'care' => 'Blot spills promptly; vacuum often',
		),
		'space/entryway' => array(
			'name' => 'Entryway', 'eyebrow' => 'A considered welcome from the first step',
			'intro' => 'Entryway rugs set the tone for the home while handling concentrated foot traffic with confidence.',
			'story' => 'An entrance rug introduces colour and craftsmanship at the threshold. A resilient construction, practical pile height, and well-fitted rug pad keep the surface secure and easy to maintain while protecting the floor beneath.',
			'bestFor' => 'Foyers, hallways, doorways, vestibules, and transition spaces.', 'character' => 'Durable, welcoming and distinctive', 'making' => 'Fitted to clear doors and edges', 'care' => 'Shake out and vacuum frequently',
		),
		'mood/warm_earthy' => array(
			'name' => 'Warm & Earthy', 'eyebrow' => 'Natural tones with an instinctive sense of home',
			'intro' => 'Clay, sand, ochre, olive, and warm neutrals create interiors that feel grounded and deeply comfortable.',
			'story' => 'Warm and earthy rugs borrow their palette from soil, stone, timber, and sun-baked landscapes. Their organic colours pair naturally with wood, leather, linen, and handmade objects, adding depth without making a room feel overly formal.',
			'bestFor' => 'Relaxed living rooms, natural-material interiors, and layered neutral schemes.', 'character' => 'Grounded, organic and comforting', 'making' => 'Built through layered natural colour', 'care' => 'Rotate to balance natural light exposure',
		),
		'mood/quiet_luxury' => array(
			'name' => 'Quiet Luxury', 'eyebrow' => 'Refinement that reveals itself slowly',
			'intro' => 'Subtle colour, exceptional fibres, and restrained detail create a lasting sense of understated refinement.',
			'story' => 'Quiet luxury favours material quality over obvious ornament. Fine tonal shifts, considered texture, and beautifully finished edges allow these rugs to support an interior rather than dominate it, rewarding a closer look and ageing gracefully.',
			'bestFor' => 'Serene bedrooms, elegant lounges, tailored interiors, and tonal schemes.', 'character' => 'Refined, tactile and understated', 'making' => 'Focused on fibre and finishing', 'care' => 'Use gentle suction and professional cleaning',
		),
		'mood/modern_minimal' => array(
			'name' => 'Modern Minimal', 'eyebrow' => 'Clarity of form, texture, and proportion',
			'intro' => 'Clean geometry and edited palettes bring warmth to modern rooms without interrupting their visual calm.',
			'story' => 'Modern minimal rugs rely on proportion, negative space, and subtle surface variation. Simple does not mean plain: shifts in pile, hand-drawn lines, and quiet tonal contrast give the room depth while preserving an uncluttered architectural feeling.',
			'bestFor' => 'Contemporary apartments, modern homes, studios, and streamlined offices.', 'character' => 'Clean, calm and architectural', 'making' => 'Precision-led and deliberately edited', 'care' => 'Vacuum regularly to preserve clean texture',
		),
		'mood/bohemian' => array(
			'name' => 'Bohemian', 'eyebrow' => 'Collected character with an easy spirit',
			'intro' => 'Expressive pattern, tactile layers, and globally inspired colour make every bohemian room feel personal.',
			'story' => 'Bohemian rugs embrace individuality through motifs, irregularities, and unexpected colour combinations. They work beautifully with vintage furniture, plants, art, and textiles, giving a layered room a unifying foundation without making it feel coordinated.',
			'bestFor' => 'Creative homes, eclectic rooms, reading corners, and relaxed layered interiors.', 'character' => 'Free-spirited, layered and expressive', 'making' => 'Rich in pattern and artisan detail', 'care' => 'Rotate and vacuum gently',
		),
		'mood/bold_artistic' => array(
			'name' => 'Bold & Artistic', 'eyebrow' => 'A floor piece with the presence of art',
			'intro' => 'Confident colour, expressive shapes, and unexpected composition turn the rug into the room\'s focal point.',
			'story' => 'Bold artistic rugs are designed to lead rather than follow. Abstract forms, saturated colour, and sculpted texture can define an otherwise restrained interior or join an already expressive collection of furniture and art.',
			'bestFor' => 'Statement living rooms, creative studios, hospitality spaces, and art-led interiors.', 'character' => 'Expressive, vivid and individual', 'making' => 'Composed like an artwork', 'care' => 'Protect strong colours from direct sunlight',
		),
		'mood/timeless_traditional' => array(
			'name' => 'Timeless Traditional', 'eyebrow' => 'Patterns shaped by generations of craft',
			'intro' => 'Classic medallions, borders, and botanical motifs bring history and enduring structure to a room.',
			'story' => 'Traditional rugs use balanced layouts and established motifs to create a sense of permanence. Their layered palettes are remarkably versatile, complementing antiques and contemporary furniture alike while carrying the visual memory of historic weaving traditions.',
			'bestFor' => 'Formal rooms, heritage homes, libraries, dining rooms, and collected interiors.', 'character' => 'Detailed, balanced and enduring', 'making' => 'Rooted in historic pattern language', 'care' => 'Rotate regularly and clean professionally',
		),
		'material/wool' => array(
			'name' => 'Wool', 'eyebrow' => 'Naturally resilient, warm, and beautifully tactile',
			'intro' => 'Wool is the enduring rug fibre: soft underfoot, resilient in daily use, and rich in natural texture.',
			'story' => 'Wool fibres have a natural crimp that helps a rug recover from footsteps and furniture. They accept dye beautifully, resist soiling, and provide warmth and acoustic comfort, making wool an exceptionally versatile choice for handcrafted rugs.',
			'bestFor' => 'Living rooms, bedrooms, dining rooms, and hardworking family spaces.', 'character' => 'Soft, resilient and naturally insulating', 'making' => 'Suited to every artisan construction', 'care' => 'Vacuum gently; blot spills immediately',
		),
		'material/silk' => array(
			'name' => 'Silk', 'eyebrow' => 'Luminous detail with exceptional finesse',
			'intro' => 'Silk brings fluid softness, fine pattern definition, and a distinctive sheen that changes with the light.',
			'story' => 'The smooth surface of silk reflects light in different directions, giving a rug remarkable visual movement. Its fine fibres allow artisans to achieve intricate detail and subtle colour, making silk rugs especially suited to considered, lower-traffic interiors.',
			'bestFor' => 'Formal lounges, primary bedrooms, dressing rooms, and collectible statement pieces.', 'character' => 'Lustrous, refined and finely detailed', 'making' => 'Slow, precise and highly skilled', 'care' => 'Professional specialist cleaning only',
		),
		'material/cotton' => array(
			'name' => 'Cotton', 'eyebrow' => 'Relaxed comfort with everyday versatility',
			'intro' => 'Cotton offers a lighter hand, clear colour, and an easygoing character for casual contemporary rooms.',
			'story' => 'Cotton yarn creates rugs that feel breathable and approachable. It is particularly effective in flatwoven and low-profile constructions, where its softness and colour clarity support practical, informal interiors.',
			'bestFor' => 'Bedrooms, kitchens, children\'s rooms, casual lounges, and layered spaces.', 'character' => 'Light, soft and easygoing', 'making' => 'Ideal for nimble, versatile weaves', 'care' => 'Follow the individual cleaning label',
		),
		'material/synthetic' => array(
			'name' => 'Synthetic', 'eyebrow' => 'Practical performance for life in motion',
			'intro' => 'Modern performance fibres offer colour stability, easy maintenance, and dependable durability for busy spaces.',
			'story' => 'Purpose-made synthetic yarns can resist moisture, stains, fading, and intensive wear. They make considered rug design accessible in spaces where practicality is essential, including outdoor rooms and active family homes.',
			'bestFor' => 'Patios, playrooms, high-traffic areas, pet-friendly homes, and hospitality use.', 'character' => 'Durable, practical and easy-care', 'making' => 'Consistent and efficient', 'care' => 'Clean according to the specific fibre label',
		),
	);
}

/** Ported from WeaveTypePage.tsx's `details` computation for isWeavePage —
 * falls back to a generic "discover the collection" block (Title-Cased from
 * the slug) for any weave type not in the curated map, exactly as the
 * original does, rather than a 404. */
function dreamrugs_get_weave_story( string $weave ): array {
	$map = dreamrugs_weave_story_map();
	if ( isset( $map[ $weave ] ) ) {
		return $map[ $weave ];
	}
	$name = ucwords( str_replace( array( '-', '_' ), ' ', $weave ) );
	return array(
		'name'      => $name,
		'eyebrow'   => 'Discover the collection',
		'intro'     => 'Explore rug designs in this weave, with sizes and finishes to suit your space.',
		'story'     => 'Browse the collection below to discover individual designs, materials, and available sizes. Each product page includes the details you need to choose your rug.',
		'bestFor'   => 'Explore individual rugs for room suggestions',
		'character' => 'Discover the texture and finish of each design',
		'making'    => 'See individual product details',
		'care'      => 'Follow the care instructions for your rug',
	);
}

/**
 * Editorial 3-image header banner (CollectionImageGrid.tsx) shown atop a
 * specific weave/material/space/mood collection page. The original app lets
 * an admin curate these per category with a multi-tier fallback (custom →
 * "default" category → matching catalog photos → bundled stock journey
 * photos); this port implements the last two tiers only (real matching
 * product photos, then a bundled fallback pool) — no per-category admin
 * curation UI yet, which would need its own settings surface. Still gives
 * every collection page a populated, real-photo banner in the common
 * (uncustomized) case.
 *
 * @param string $facet 'weave' | 'material' | 'space' | 'mood'
 * @param string $value the slug/value within that facet
 * @return array{0: array{image_url:string,caption:string}, 1: ..., 2: ...} exactly 3 images
 */
function dreamrugs_collection_display_images( string $facet, string $value ): array {
	$query_args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 3,
		'orderby'        => 'rand',
		'meta_query'     => array( array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small catalog, homepage-adjacent decorative query, not a hot path.
	);
	if ( 'weave' === $facet ) {
		$query_args['meta_query'][] = array( 'key' => '_drc_weave_type', 'value' => $value );
	} elseif ( 'material' === $facet ) {
		$query_args['tax_query'] = array( array( 'taxonomy' => 'material_type', 'field' => 'slug', 'terms' => $value ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	} elseif ( 'space' === $facet ) {
		$query_args['tax_query'] = array( array( 'taxonomy' => 'room_type', 'field' => 'slug', 'terms' => $value ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	} elseif ( 'mood' === $facet ) {
		$query_args['tax_query'] = array( array( 'taxonomy' => 'mood_tag', 'field' => 'slug', 'terms' => $value ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}

	$images = array();
	$matches = new WP_Query( $query_args );
	foreach ( $matches->posts as $post ) {
		$url = get_the_post_thumbnail_url( $post, 'large' );
		if ( $url ) {
			$images[] = array( 'image_url' => $url, 'caption' => '' );
		}
	}
	wp_reset_postdata();

	if ( count( $images ) < 3 ) {
		foreach ( DRC_Content_Types::get_workshop_photos() as $photo ) {
			if ( count( $images ) >= 3 ) {
				break;
			}
			$images[] = array( 'image_url' => $photo['image_url'], 'caption' => '' );
		}
	}
	// Still short (an essentially empty catalog/media library) — repeat the
	// last known image rather than emitting a broken <img src="">.
	while ( count( $images ) < 3 && ! empty( $images ) ) {
		$images[] = end( $images );
	}
	return array_slice( $images, 0, 3 );
}

/** Ported from WeaveTypePage.tsx's `details` computation for the
 * facet/value (space/mood/material) branch — falls back to a generic
 * per-value block for any material/space/mood value not in the curated map;
 * returns null for a facet WeaveTypePage.tsx itself wouldn't recognize
 * either (its `undefined` case), leaving the caller to decide what to do
 * (archive-product.php simply omits the story block in that case). */
function dreamrugs_get_collection_story( string $facet, string $value ): ?array {
	$map = dreamrugs_collection_story_map();
	$key = "{$facet}/{$value}";
	if ( isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}
	if ( ! in_array( $facet, array( 'material', 'space', 'mood' ), true ) ) {
		return null;
	}
	$label = str_replace( array( '-', '_' ), ' ', $value );
	return array(
		'name'      => $label,
		'eyebrow'   => 'Discover the collection',
		'intro'     => "Explore the {$label} collection, in sizes and finishes to suit your space.",
		'story'     => 'Browse the designs below for material details, available sizes, and finishes.',
		'bestFor'   => 'See individual rugs for room suggestions',
		'character' => 'Discover the texture and finish of each design',
		'making'    => 'See individual product details',
		'care'      => 'Follow the care instructions for your rug',
	);
}
