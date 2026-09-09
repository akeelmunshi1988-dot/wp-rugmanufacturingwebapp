<?php
/**
 * Admin-editable /about page content — ported from
 * frontend/src/data/aboutPageContent.ts's ABOUT_PAGE_DEFAULTS/mergeAboutPage().
 * Stored as DRC_Settings::get('about_page') (the existing 'json' field on the
 * Settings screen — see assets/admin.js's SETTINGS_FIELDS), deep-merged over
 * these defaults so any missing section/field falls back to today's copy
 * and the page can never render blank. `{business}` in any text field is
 * replaced with the site's name at render time, matching the original's
 * `sub()` helper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dreamrugs_about_page_defaults(): array {
	return array(
		'hero'        => array(
			'enabled'   => true,
			'eyebrow'   => 'The story behind every thread',
			'heading'   => "Rugs with a sense\nof place and permanence.",
			'body'      => 'At {business}, traditional rug making meets a more personal way of living. We create made-to-measure pieces with depth, restraint and the unmistakable character of work shaped by hand.',
			'cta_label' => 'Begin a custom rug',
			'image_url' => '',
			'image_alt' => 'Handcrafted rug in a warm, natural living room',
		),
		'credentials' => array(
			'enabled' => true,
			'items'   => array(
				array( 'title' => 'Made individually', 'subtitle' => 'Never pulled from a production line' ),
				array( 'title' => 'Sized for your space', 'subtitle' => 'Proportion considered from the start' ),
				array( 'title' => 'Reviewed by hand', 'subtitle' => 'Quality checked before dispatch' ),
			),
		),
		'story'       => array(
			'enabled'             => true,
			'eyebrow'             => 'Our point of view',
			'heading'             => "Made slowly.\nLived with fully.",
			'quote'               => 'Luxury is not excess. It is the time, judgement and human touch held within an object.',
			'primary_image_url'   => '',
			'primary_image_alt'   => 'The rug-making workshop',
			'secondary_image_url' => '',
			'secondary_image_alt' => 'Natural fibres prepared for weaving',
			'images'              => null,
		),
		'process'     => array(
			'enabled' => true,
			'eyebrow' => 'The making of a rug',
			'heading' => "From an idea on paper\nto a surface underfoot.",
			'intro'   => 'A made-to-order rug passes through many hands. Each stage is deliberate, and each one leaves a quiet trace in the finished piece.',
			'steps'   => array(
				array( 'number' => '01', 'title' => 'A considered beginning', 'text' => 'Every rug begins with the room: its proportions, light, movement and atmosphere. We refine the scale, palette and construction before a single thread reaches the loom.', 'image_url' => '', 'image_alt' => 'Custom rug design planning' ),
				array( 'number' => '02', 'title' => 'Material selection', 'text' => 'Fibres are chosen for both beauty and purpose—from resilient wool and relaxed cotton to luminous silk and performance-led blends.', 'image_url' => '', 'image_alt' => 'Selecting rug fibres and materials' ),
				array( 'number' => '03', 'title' => 'Made at the loom', 'text' => 'Skilled hands translate the design knot by knot, line by line. This unhurried process gives each surface its depth, character and quiet individuality.', 'image_url' => '', 'image_alt' => 'Artisan weaving a rug at the loom' ),
				array( 'number' => '04', 'title' => 'Finished by hand', 'text' => 'The rug is washed, stretched, carved where required and carefully inspected. Edges, pile, colour and dimensions are reviewed before dispatch.', 'image_url' => '', 'image_alt' => 'Hand finishing and inspecting a rug' ),
			),
		),
		'principles'  => array(
			'enabled' => true,
			'eyebrow' => 'What matters to us',
			'heading' => 'Beauty begins with integrity.',
			'intro'   => 'The qualities you feel in a finished rug are the result of decisions made long before it enters a room—who makes it, what it is made from, and how carefully it is finished.',
			'items'   => array(
				array( 'icon' => 'hand', 'title' => 'Artisan made', 'text' => 'Human skill remains at the centre of every piece, from yarn preparation to final finishing.' ),
				array( 'icon' => 'ruler', 'title' => 'Made to measure', 'text' => 'Proportion is part of the design. Every rug can be tailored to the dimensions of its setting.' ),
				array( 'icon' => 'leaf', 'title' => 'Thoughtful materials', 'text' => 'We select fibres for longevity, tactility and the way they will live in your home.' ),
				array( 'icon' => 'shield-check', 'title' => 'Inspected individually', 'text' => 'Each finished rug is reviewed by hand against the approved specification before it leaves us.' ),
			),
		),
		'founder'     => array(
			'enabled'   => true,
			'eyebrow'   => 'A note from the founder',
			'heading'   => 'Craft is a responsibility.',
			'body'      => "For more than two decades, our founder has worked alongside makers who understand that excellence is rarely one grand gesture. It is hundreds of small decisions, repeated with patience and care.\n\n{business} was founded to bring that standard directly to the people who will live with these rugs—to make the process more transparent, more personal and more faithful to the hands behind the work.",
			'name'      => '',
			'role'      => 'Founder',
			'caption'   => 'A culture of care and precision',
			'image_url' => '',
			'image_alt' => 'Inside the rug workshop',
		),
		'cta'         => array(
			'enabled'         => true,
			'eyebrow'         => 'Let us make something lasting',
			'heading'         => 'Your room is the beginning of the design.',
			'body'            => 'Share your dimensions, references and ideas. We will help you consider scale, material, colour and construction before your rug reaches the loom.',
			'primary_label'   => 'Request a custom rug',
			'secondary_label' => 'Explore the collection',
		),
	);
}

/** Deep-merges a stored (possibly partial/empty) about_page value over the
 * defaults — mirrors mergeAboutPage() exactly, including its per-section
 * list-or-fallback handling for repeatable arrays (credentials.items,
 * process.steps, principles.items). */
function dreamrugs_get_about_page( array $stored ): array {
	$defaults = dreamrugs_about_page_defaults();
	$merge_scalars = function ( array $default_section, $stored_section ) {
		return array_merge( $default_section, is_array( $stored_section ) ? $stored_section : array() );
	};
	$merge_list = function ( $value, array $fallback ) {
		return is_array( $value ) && ! empty( $value ) ? array_values( $value ) : $fallback;
	};

	$process    = $merge_scalars( $defaults['process'], $stored['process'] ?? null );
	$principles = $merge_scalars( $defaults['principles'], $stored['principles'] ?? null );

	return array(
		'hero'        => $merge_scalars( $defaults['hero'], $stored['hero'] ?? null ),
		'credentials' => array(
			'enabled' => $merge_scalars( $defaults['credentials'], $stored['credentials'] ?? null )['enabled'],
			'items'   => $merge_list( $stored['credentials']['items'] ?? null, $defaults['credentials']['items'] ),
		),
		'story'       => $merge_scalars( $defaults['story'], $stored['story'] ?? null ),
		'process'     => array_merge( $process, array( 'steps' => $merge_list( $stored['process']['steps'] ?? null, $defaults['process']['steps'] ) ) ),
		'principles'  => array_merge( $principles, array( 'items' => $merge_list( $stored['principles']['items'] ?? null, $defaults['principles']['items'] ) ) ),
		'founder'     => $merge_scalars( $defaults['founder'], $stored['founder'] ?? null ),
		'cta'         => $merge_scalars( $defaults['cta'], $stored['cta'] ?? null ),
	);
}

/** Replaces "{business}" with the site name — mirrors the original's sub(). */
function dreamrugs_about_sub( string $text, string $business_name ): string {
	return str_replace( '{business}', $business_name, $text );
}

/** Splits on blank lines into paragraphs — mirrors the original's paragraphs(). */
function dreamrugs_about_paragraphs( string $text, string $business_name ): array {
	$text = dreamrugs_about_sub( $text, $business_name );
	return array_values( array_filter( array_map( 'trim', preg_split( "/\n{2,}/", $text ) ) ) );
}
