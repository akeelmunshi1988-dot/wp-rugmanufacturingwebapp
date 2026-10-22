<?php
/**
 * Storefront back-to-top button drawn as a small rug — port of the original
 * app's frontend/src/components/ScrollToTopRug.tsx (behaviour lives in
 * assets/scroll-top-rug.js). The artwork follows the Hand-Knotted Wool
 * Checkerboard Carpet: taupe/cream checks with braided tassels at both ends.
 * Always bottom-6: the original raises it above the AI chat bubble, which
 * this port doesn't have (see header.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<button
	type="button"
	x-data="drcRugTop()"
	x-show="visible"
	x-cloak
	@click="backToTop()"
	aria-label="Back to top"
	title="Back to top"
	class="group fixed right-6 bottom-6 z-40 h-[90px] w-[60px] transition-transform duration-300 hover:-translate-y-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-showroom-charcoal motion-reduce:transition-none"
>
	<!-- Soft shadow under the rug -->
	<span aria-hidden="true" class="absolute inset-x-2 bottom-0 h-2 rounded-full bg-showroom-charcoal/15 blur-[3px] transition-opacity motion-reduce:transition-none" :style="shadowStyle()"></span>

	<!-- The flat rug, revealed top-down as the roll travels down -->
	<span aria-hidden="true" class="absolute inset-x-1.5 top-3 overflow-hidden motion-reduce:!transition-none" :style="rugStyle()">
		<svg viewBox="0 0 40 54" class="h-full w-full" aria-hidden="true">
			<defs>
				<pattern id="drc-top-checks" x="5" y="5" width="10" height="9.78" patternUnits="userSpaceOnUse">
					<rect width="10" height="9.78" fill="#F1EADC" />
					<rect width="5" height="4.89" fill="#9C8366" />
					<rect x="5" y="4.89" width="5" height="4.89" fill="#9C8366" />
				</pattern>
				<!-- Roughens the check edges like a shaggy wool pile -->
				<filter id="drc-top-pile" x="-5%" y="-5%" width="110%" height="110%">
					<feTurbulence type="fractalNoise" baseFrequency="1.6" numOctaves="2" seed="7" result="noise" />
					<feDisplacementMap in="SourceGraphic" in2="noise" scale="0.9" />
				</filter>
				<linearGradient id="drc-top-sheen" x1="0" y1="0" x2="1" y2="1">
					<stop offset="0" stop-color="#fff" stop-opacity="0.18" />
					<stop offset="0.5" stop-color="#fff" stop-opacity="0" />
					<stop offset="1" stop-color="#3b2f22" stop-opacity="0.12" />
				</linearGradient>
			</defs>
			<!-- braided tassels: top ones point up, bottom ones down -->
			<?php foreach ( array( 6.5, 10.5, 14.5, 18.5, 21.5, 25.5, 29.5, 33.5 ) as $x ) : ?>
				<g stroke-linecap="round">
					<path d="M<?php echo esc_attr( $x ); ?> 5 l0.45 -0.8 l-0.9 -0.8 l0.9 -0.8" fill="none" stroke="#b9a184" stroke-width="0.75" />
					<path d="M<?php echo esc_attr( $x ); ?> 2.6 l-0.9 -2 M<?php echo esc_attr( $x ); ?> 2.6 l0 -2.2 M<?php echo esc_attr( $x ); ?> 2.6 l0.9 -2" stroke="#c8b497" stroke-width="0.55" />
					<path d="M<?php echo esc_attr( $x ); ?> 49 l0.45 0.8 l-0.9 0.8 l0.9 0.8" fill="none" stroke="#b9a184" stroke-width="0.75" />
					<path d="M<?php echo esc_attr( $x ); ?> 51.4 l-0.9 2 M<?php echo esc_attr( $x ); ?> 51.4 l0 2.2 M<?php echo esc_attr( $x ); ?> 51.4 l0.9 2" stroke="#c8b497" stroke-width="0.55" />
				</g>
			<?php endforeach; ?>
			<!-- the rug body -->
			<rect x="5" y="5" width="30" height="44" fill="url(#drc-top-checks)" filter="url(#drc-top-pile)" />
			<rect x="5" y="5" width="30" height="44" fill="url(#drc-top-sheen)" />
		</svg>

		<!-- up arrow in a cream roundel at the rug's centre, fades in once the rug is open -->
		<span class="absolute inset-x-0 top-[20px] flex justify-center transition-all duration-300 group-hover:-translate-y-0.5 motion-reduce:transition-none" :style="arrowStyle()">
			<span class="flex h-[26px] w-[26px] items-center justify-center rounded-full bg-[#F8F4EC] text-[#5f4d3b] shadow-[0_1px_3px_rgba(33,31,29,0.25)] ring-1 ring-[#9C8366]">
				<svg viewBox="0 0 16 16" class="h-[15px] w-[15px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M8 13V3M3.5 7.5 8 3l4.5 4.5" />
				</svg>
			</span>
		</span>
	</span>

	<!-- The roll: the same checkerboard rolled up — sits at the top while rolled, travels to the bottom edge and thins out as it unrolls -->
	<span aria-hidden="true" class="absolute inset-x-0.5 top-1 h-4 rounded-full border border-[#8a7258] motion-reduce:!transition-none" :style="rollStyle()">
		<span class="absolute -left-px top-1/2 h-4 w-2.5 -translate-y-1/2 rounded-full border border-[#8a7258] bg-[radial-gradient(circle,#F1EADC_22%,#9C8366_28%,#9C8366_42%,#F1EADC_48%,#F1EADC_62%,#9C8366_68%)]"></span>
		<span class="absolute -right-px top-1/2 h-4 w-2.5 -translate-y-1/2 rounded-full border border-[#8a7258] bg-[radial-gradient(circle,#F1EADC_22%,#9C8366_28%,#9C8366_42%,#F1EADC_48%,#F1EADC_62%,#9C8366_68%)]"></span>
	</span>
</button>
