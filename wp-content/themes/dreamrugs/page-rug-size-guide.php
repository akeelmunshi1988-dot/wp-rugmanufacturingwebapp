<?php
/**
 * Ported from frontend/src/pages/RugServices.tsx, page: 'size'. Static
 * content — no admin-editable settings for this page in the original app
 * either, so this is a straight markup port (including the inline SVG
 * room-placement diagrams) rather than something reading dreamrugs_settings().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One room-placement diagram — port of RugServices.tsx's <RoomPlan>. */
function dreamrugs_room_plan_svg( string $room ): string {
	$furniture = array(
		'living'   => '<rect x="72" y="55" width="256" height="60" rx="8" /><path d="M80 96h240M156 55v40M244 55v40" fill="none" /><rect x="130" y="144" width="140" height="76" rx="18" /><rect x="70" y="228" width="62" height="55" rx="8" /><rect x="268" y="228" width="62" height="55" rx="8" />',
		'dining'   => '<rect x="120" y="89" width="160" height="154" rx="55" />' . implode( '', array_map( function ( $y ) {
			return '<g><rect x="84" y="' . $y . '" width="28" height="35" rx="4" /><rect x="288" y="' . $y . '" width="28" height="35" rx="4" /></g>';
		}, array( 100, 160, 220 ) ) ),
		'bedroom'  => '<rect x="112" y="20" width="176" height="210" rx="5" /><rect x="122" y="30" width="70" height="40" rx="5" /><rect x="208" y="30" width="70" height="40" rx="5" /><path d="M112 82h176" /><rect x="60" y="22" width="38" height="38" /><rect x="302" y="22" width="38" height="38" />',
	);
	$rug = 'dining' === $room
		? '<ellipse cx="200" cy="165" rx="156" ry="128" fill="url(#threads-' . $room . ')" stroke="#a9785d" />'
		: '<rect x="48" y="35" width="304" height="260" fill="url(#threads-' . $room . ')" stroke="#a9785d" />';
	return '<svg viewBox="0 0 400 330" role="img" aria-label="' . esc_attr( $room ) . ' rug placement diagram" class="w-full bg-[#e7e0d4]">'
		. '<defs><pattern id="threads-' . esc_attr( $room ) . '" width="7" height="7" patternUnits="userSpaceOnUse"><path d="M1 0v7" stroke="#b79c87" stroke-width="0.5" /></pattern></defs>'
		. $rug
		. '<g fill="#f8f5ef" stroke="#776e61" stroke-width="2">' . ( $furniture[ $room ] ?? '' ) . '</g>'
		. '</svg>';
}

$rooms = array(
	array( 'room' => 'living', 'title' => 'Living Room', 'text' => 'Place the front legs of your sofa and chairs on the rug to connect the seating area. For a larger room, consider a rug that holds the full furniture arrangement.' ),
	array( 'room' => 'dining', 'title' => 'Dining Room', 'text' => 'Allow enough rug beyond the table for chairs to stay on it when pulled out. Measure the table with its extensions in place and test your usual chair position.' ),
	array( 'room' => 'bedroom', 'title' => 'Bedroom', 'text' => 'Extend the rug beyond the sides and foot of the bed for a comfortable landing. Account for bedside tables, door clearance and the space around the bed.' ),
);

get_header();
?>

<section class="bg-[#20221c] px-[5vw] py-20 text-[#f7f5ef] md:py-28">
	<div>
		<p class="storefront-eyebrow mb-6 text-white/80">A considered fit</p>
		<h1 class="storefront-heading max-w-2xl text-5xl md:text-6xl text-white">The right rug. The right proportions.</h1>
		<div class="mt-8 max-w-lg space-y-6 text-sm leading-7 opacity-80">
			<p>Start with the furniture, then find the rug that brings it together. These placement guides are a starting point; measure your own room before choosing a size.</p>
		</div>
	</div>
</section>

<section class="grid divide-y divide-stone-300 bg-[#f7f5ef] md:grid-cols-3 md:divide-x md:divide-y-0">
	<?php foreach ( $rooms as $i => $r ) : ?>
		<article class="px-[4vw] py-14 text-[#20221c]">
			<p class="storefront-eyebrow">0<?php echo esc_html( $i + 1 ); ?></p>
			<h2 class="storefront-heading my-5 text-3xl"><?php echo esc_html( $r['title'] ); ?></h2>
			<?php echo dreamrugs_room_plan_svg( $r['room'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG built from a fixed internal map, no user input ?>
			<p class="mt-6 text-sm leading-7 text-stone-600"><?php echo esc_html( $r['text'] ); ?></p>
		</article>
	<?php endforeach; ?>
</section>

<section class="bg-[#e8e2d6] px-[5vw] py-14 text-[#20221c]">
	<h2 class="storefront-heading text-3xl">Measure before you decide.</h2>
	<p class="my-5 max-w-3xl text-sm leading-7 text-stone-600">Mark the proposed rug edges with tape, check furniture and doors, then note the width and length with your measurement unit. For a round rug, measure the diameter. Send your dimensions with your enquiry for a bespoke size.</p>
	<a href="<?php echo esc_url( home_url( '/custom-rug-request' ) ); ?>" class="inline-flex items-center justify-center bg-[#20221c] px-6 py-4 text-[11px] font-semibold uppercase tracking-[0.15em] text-white hover:bg-[#414436] transition-colors">Discuss your rug size ↗</a>
</section>

<?php get_footer(); ?>
