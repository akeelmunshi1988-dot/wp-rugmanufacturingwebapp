<?php
/**
 * Ported from the original app's frontend/src/components/CustomerLayout.tsx
 * (header portion). Markup/classes kept identical; React useState is
 * replaced with Alpine.js x-data, and settings/auth/cart state that the
 * SPA fetched client-side are now rendered server-side by PHP (no client
 * fetch needed for these).
 *
 * Adaptations from the original (WordPress's unified user model differs
 * from the original app's separate StaffUser/Customer tables — there is no
 * "admin browsing as a customer" duality here, just is_user_logged_in()
 * plus an extra Admin Panel link for users who can manage_woocommerce):
 * - Currency switcher is stubbed to a static display (single currency) —
 *   multi-currency is flagged as likely-dropped in the migration plan;
 *   swap in a real WOOCS-backed dropdown here if that decision reverses.
 * - Announcement bar: reads the drc_announcement CPT
 *   (DRC_Content_Types::get_announcements()) and rotates through the active
 *   entries with the same 5s interval / 300ms fade as CustomerLayout.tsx.
 *   Falls back to a single static line when no announcements are configured.
 * - The customer-facing AI chat widget (CustomerChat.tsx) is intentionally
 *   not ported — dropped per the migration plan's AI-feature-scope decision.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$drc_settings   = dreamrugs_settings();
$business_name  = get_bloginfo( 'name' ) ?: 'Your Rug Studio';
$logo_id        = get_theme_mod( 'custom_logo' );
$logo_url       = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : null;
$cart_count     = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
$current_user   = wp_get_current_user();
$is_logged_in   = is_user_logged_in();
$is_admin_user  = current_user_can( 'manage_woocommerce' );
$default_announcement = 'Handcrafted, made to order — every rug, every size.';

// Admin-configured announcement messages (drc_announcement CPT, active-only,
// sort_order). Empty ⇒ the single $default_announcement line renders instead,
// exactly like CustomerLayout.tsx's `activeAnnouncement?.text ?? '…'`.
$drc_announcements = class_exists( 'DRC_Content_Types' ) ? DRC_Content_Types::get_announcements() : array();

$nav_items = array(
	array( 'path' => home_url( '/' ), 'label' => 'Home' ),
	array( 'path' => get_permalink( wc_get_page_id( 'shop' ) ), 'label' => 'Collection' ),
	array( 'path' => home_url( '/custom-rug-request' ), 'label' => 'Custom Rug' ),
	array( 'path' => home_url( '/about' ), 'label' => 'About Us' ),
);

$mega_menu = array(
	'space' => array(
		'heading' => 'Shop by Space',
		'links'   => array(
			array( 'label' => 'Living Room', 'to' => home_url( '/catalog/space/living_room' ) ),
			array( 'label' => 'Bedroom', 'to' => home_url( '/catalog/space/bedroom' ) ),
			array( 'label' => 'Dining Room', 'to' => home_url( '/catalog/space/dining_room' ) ),
			array( 'label' => 'Entryway', 'to' => home_url( '/catalog/space/entryway' ) ),
		),
	),
	'mood' => array(
		'heading' => 'Shop by Mood',
		'links'   => array(
			array( 'label' => 'Warm & Earthy', 'to' => home_url( '/catalog/mood/warm_earthy' ) ),
			array( 'label' => 'Quiet Luxury', 'to' => home_url( '/catalog/mood/quiet_luxury' ) ),
			array( 'label' => 'Modern Minimal', 'to' => home_url( '/catalog/mood/modern_minimal' ) ),
			array( 'label' => 'Bohemian', 'to' => home_url( '/catalog/mood/bohemian' ) ),
			array( 'label' => 'Bold & Artistic', 'to' => home_url( '/catalog/mood/bold_artistic' ) ),
			array( 'label' => 'Timeless Traditional', 'to' => home_url( '/catalog/mood/timeless_traditional' ) ),
		),
	),
	'material' => array(
		'heading' => 'Shop by Material',
		'links'   => array(
			array( 'label' => 'Wool', 'to' => home_url( '/catalog/material/wool' ) ),
			array( 'label' => 'Silk', 'to' => home_url( '/catalog/material/silk' ) ),
			array( 'label' => 'Cotton', 'to' => home_url( '/catalog/material/cotton' ) ),
			array( 'label' => 'Synthetic', 'to' => home_url( '/catalog/material/synthetic' ) ),
		),
	),
	'quick' => array(
		'heading' => 'Quick Links',
		'links'   => array(
			array( 'label' => 'All Rugs', 'to' => get_permalink( wc_get_page_id( 'shop' ) ) ),
			array( 'label' => 'Custom Rug Request', 'to' => home_url( '/custom-rug-request' ) ),
		),
	),
);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'min-h-screen bg-white flex flex-col font-sans' ); ?>
	x-data="{
		scrolled: false,
		mobileOpen: false,
		dropdownOpen: false,
		currencyOpen: false,
		infoBarDismissed: false,
		announceFading: false,
		announceIndex: 0,
		announceItems: <?php echo esc_attr( wp_json_encode( array_map( function ( $a ) {
			return array( 'text' => $a['text'], 'link_url' => $a['link_url'] ?: null );
		}, array_values( $drc_announcements ) ) ) ); ?>,
		get activeAnnouncement() {
			return this.announceItems.length ? this.announceItems[this.announceIndex % this.announceItems.length] : null;
		},
		startAnnounceRotation() {
			if (this.announceItems.length <= 1) return;
			setInterval(() => {
				this.announceFading = true;
				setTimeout(() => {
					this.announceIndex = (this.announceIndex + 1) % this.announceItems.length;
					this.announceFading = false;
				}, 300);
			}, 5000);
		},
		showCookieBanner: ! localStorage.getItem('cookie_consent')
	}"
	x-init="startAnnounceRotation()"
	@scroll.window="scrolled = (window.scrollY > 20)"
>
<?php wp_body_open(); ?>

<header
	class="fixed top-0 left-0 right-0 z-40 bg-white transition-shadow duration-300"
	:class="scrolled ? 'shadow-[0_1px_0_0_#e7e5e0]' : 'border-b border-stone-100'"
>
	<div x-show="!infoBarDismissed" class="relative flex items-center justify-center gap-6 bg-stone-900 text-stone-200 text-xs tracking-wide px-10 h-8">
		<span class="truncate transition-opacity duration-300" :class="announceFading ? 'opacity-0' : 'opacity-100'">
			<?php
			// No-JS / pre-hydration text: the first active announcement, or the
			// static default. Alpine swaps in the link/plain variants below
			// once ready (and rotates them when there are 2+).
			$first_announcement = ! empty( $drc_announcements ) ? $drc_announcements[0] : null;
			$initial_text       = $first_announcement ? $first_announcement['text'] : $default_announcement;
			?>
			<span x-show="!activeAnnouncement"><?php echo esc_html( $initial_text ); ?></span>
			<a x-show="activeAnnouncement && activeAnnouncement.link_url" x-cloak :href="activeAnnouncement && activeAnnouncement.link_url" class="hover:text-white transition-colors" x-text="activeAnnouncement && activeAnnouncement.text"></a>
			<span x-show="activeAnnouncement && !activeAnnouncement.link_url" x-cloak x-text="activeAnnouncement && activeAnnouncement.text"></span>
		</span>
		<?php if ( ! empty( $drc_settings['contact_phones'][0] ) || ! empty( $drc_settings['contact_address'] ) ) : ?>
		<span class="hidden md:flex items-center gap-6 text-stone-400 flex-shrink-0">
			<?php if ( ! empty( $drc_settings['contact_address'] ) ) : ?>
				<span class="inline-flex items-center gap-1.5"><?php dreamrugs_icon( 'map-pin', 11 ); ?> Visit Us</span>
			<?php endif; ?>
			<?php if ( ! empty( $drc_settings['contact_phones'][0] ) ) : ?>
				<a href="tel:<?php echo esc_attr( $drc_settings['contact_phones'][0] ); ?>" class="inline-flex items-center gap-1.5 hover:text-white transition-colors">
					<?php dreamrugs_icon( 'phone', 11 ); ?> <?php echo esc_html( $drc_settings['contact_phones'][0] ); ?>
				</a>
			<?php endif; ?>
		</span>
		<?php endif; ?>
		<button @click="infoBarDismissed = true" aria-label="Dismiss" class="absolute right-3 text-stone-400 hover:text-white transition-colors">
			<?php dreamrugs_icon( 'x', 13 ); ?>
		</button>
	</div>

	<div class="relative w-[94vw] max-w-none mx-auto px-4 h-[70px] flex items-center gap-8">

		<nav class="hidden lg:flex items-center gap-7 h-full">
			<?php foreach ( $nav_items as $n ) :
				$active = ( untrailingslashit( $n['path'] ) === untrailingslashit( home_url( $_SERVER['REQUEST_URI'] ?? '' ) ) );
				if ( $n['label'] !== 'Collection' ) : ?>
					<a href="<?php echo esc_url( $n['path'] ); ?>"
						class="storefront-nav-link border-b <?php echo $active ? 'text-stone-900 border-stone-900' : 'text-stone-500 hover:text-stone-900 border-transparent hover:border-stone-300'; ?>">
						<?php echo esc_html( $n['label'] ); ?>
					</a>
				<?php else : ?>
					<div class="group/mega h-full flex items-center">
						<a href="<?php echo esc_url( $n['path'] ); ?>"
							class="storefront-nav-link border-b <?php echo $active ? 'text-stone-900 border-stone-900' : 'text-stone-500 hover:text-stone-900 border-transparent hover:border-stone-300'; ?>">
							<?php echo esc_html( $n['label'] ); ?>
						</a>
						<div class="hidden group-hover/mega:block fixed left-0 right-0 bg-cream-200 border-t border-b border-stone-100 shadow-lg z-30"
							:class="infoBarDismissed ? 'top-[70px]' : 'top-[102px]'">
							<div class="w-[94vw] max-w-none mx-auto px-4 py-10 grid grid-cols-5 gap-10">
								<?php foreach ( $mega_menu as $section ) : ?>
									<div class="space-y-3">
										<p class="text-stone-900 text-xs font-semibold uppercase tracking-widest"><?php echo esc_html( $section['heading'] ); ?></p>
										<div class="space-y-2.5">
											<?php foreach ( $section['links'] as $l ) : ?>
												<a href="<?php echo esc_url( $l['to'] ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors"><?php echo esc_html( $l['label'] ); ?></a>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endforeach; ?>
								<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="group/promo relative overflow-hidden bg-stone-100 aspect-[4/5] block">
									<img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/shop-by-space-living-room.jpg' ); ?>" alt=""
										class="absolute inset-0 w-full h-full object-cover group-hover/promo:scale-105 transition-transform duration-500" />
									<div class="absolute inset-0 bg-gradient-to-t from-stone-950/70 via-transparent to-transparent"></div>
									<span class="absolute bottom-4 left-4 right-4 text-white font-serif text-lg font-light">Explore the Full Collection</span>
								</a>
							</div>
						</div>
					</div>
				<?php endif;
			endforeach; ?>
		</nav>

		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="inline-flex items-center -ml-3 lg:absolute lg:left-1/2 lg:-ml-0 lg:-translate-x-1/2">
			<span class="inline-flex items-center gap-1.5">
				<?php if ( $logo_url ) : ?>
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="" class="flex-shrink-0 object-contain" style="height:46px;width:46px" />
				<?php endif; ?>
				<span class="font-serif text-xl font-medium tracking-wide text-[var(--drc-primary)]"><?php echo esc_html( $business_name ); ?></span>
			</span>
		</a>

		<div class="hidden lg:flex items-center gap-5 ml-auto">
			<div class="relative">
				<select id="drc-currency-select" aria-label="Display currency" class="appearance-none bg-transparent text-sm text-stone-500 hover:text-stone-900 pr-4 py-1 focus:outline-none cursor-pointer transition-colors">
					<?php
					$drc_currencies = array(
						'INR' => 'INR — ₹', 'USD' => 'USD — $', 'EUR' => 'EUR — €', 'GBP' => 'GBP — £',
						'CAD' => 'CAD — CA$', 'AUD' => 'AUD — A$', 'AED' => 'AED', 'SGD' => 'SGD — S$',
						'CHF' => 'CHF', 'SAR' => 'SAR', 'QAR' => 'QAR', 'JPY' => 'JPY — ¥',
						'NZD' => 'NZD — NZ$', 'ZAR' => 'ZAR — R',
					);
					foreach ( $drc_currencies as $code => $label ) :
					?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="pointer-events-none absolute right-0 top-1/2 -translate-y-1/2 text-stone-400"><?php dreamrugs_icon( 'chevron-down', 11 ); ?></span>
			</div>

			<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="relative text-stone-500 hover:text-stone-900 transition-colors">
				<?php dreamrugs_icon( 'shopping-cart', 18 ); ?>
				<span id="drc-header-cart-badge" data-drc-cart-count="<?php echo (int) $cart_count; ?>" class="absolute -top-2 -right-2 w-4 h-4 rounded-full bg-stone-900 text-white text-[10px] leading-4 text-center" <?php echo $cart_count > 0 ? '' : 'style="display:none"'; ?>><?php echo (int) $cart_count; ?></span>
			</a>

			<?php if ( $is_logged_in ) : ?>
				<div class="relative" @click.outside="dropdownOpen = false">
					<button @click="dropdownOpen = !dropdownOpen" class="flex items-center gap-2 text-sm text-stone-600 hover:text-stone-900 transition-colors">
						<div class="w-7 h-7 rounded-full bg-stone-100 flex items-center justify-center"><?php dreamrugs_icon( 'user', 13, 'text-stone-600' ); ?></div>
						<span class="font-medium"><?php echo esc_html( explode( ' ', $current_user->display_name )[0] ); ?></span>
						<?php if ( $is_admin_user ) : ?><span class="text-xs bg-stone-900 text-white px-1.5 py-0.5 tracking-wider uppercase">Admin</span><?php endif; ?>
						<span :class="dropdownOpen ? 'rotate-180' : ''" class="transition-transform text-stone-400"><?php dreamrugs_icon( 'chevron-down', 12 ); ?></span>
					</button>
					<div x-show="dropdownOpen" x-cloak class="absolute right-0 top-full mt-3 w-52 bg-white border border-stone-200 shadow-lg z-50">
						<div class="px-4 py-3 border-b border-stone-100">
							<p class="text-stone-900 text-sm font-medium truncate"><?php echo esc_html( $current_user->display_name ); ?></p>
							<p class="text-stone-400 text-xs truncate mt-0.5"><?php echo esc_html( $current_user->user_email ); ?></p>
						</div>
						<div class="py-1">
							<a href="<?php echo esc_url( home_url( '/my-orders' ) ); ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm text-stone-600 hover:text-stone-900 hover:bg-stone-50 transition-colors"><span class="text-stone-400"><?php dreamrugs_icon( 'package', 13 ); ?></span> My Orders</a>
							<a href="<?php echo esc_url( home_url( '/my-quotes' ) ); ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm text-stone-600 hover:text-stone-900 hover:bg-stone-50 transition-colors"><span class="text-stone-400"><?php dreamrugs_icon( 'file-text', 13 ); ?></span> My Quotes</a>
						</div>
						<?php if ( $is_admin_user ) : ?>
						<div class="py-1">
							<a href="<?php echo esc_url( admin_url() ); ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm text-stone-600 hover:text-stone-900 hover:bg-stone-50 transition-colors"><?php dreamrugs_icon( 'layout-dashboard', 13, 'text-stone-400' ); ?> Admin Panel</a>
						</div>
						<?php endif; ?>
						<div class="border-t border-stone-100 py-1">
							<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-stone-500 hover:text-red-600 hover:bg-red-50 transition-colors"><?php dreamrugs_icon( 'log-out', 13 ); ?> Sign out</a>
						</div>
					</div>
				</div>
			<?php else : ?>
				<a href="<?php echo esc_url( wp_login_url() ); ?>" class="text-sm text-stone-500 hover:text-stone-900 transition-colors tracking-wide">Sign In</a>
			<?php endif; ?>
		</div>

		<button class="ml-auto lg:hidden text-stone-700 p-1" @click="mobileOpen = !mobileOpen">
			<span x-show="!mobileOpen"><?php dreamrugs_icon( 'menu', 20 ); ?></span>
			<span x-show="mobileOpen" x-cloak><?php dreamrugs_icon( 'x', 20 ); ?></span>
		</button>
	</div>

	<div x-show="mobileOpen" x-cloak class="lg:hidden bg-white border-t border-stone-100 px-6 py-5 space-y-1">
		<?php foreach ( $nav_items as $n ) : ?>
			<a href="<?php echo esc_url( $n['path'] ); ?>" class="block py-2.5 text-sm text-stone-700 hover:text-stone-900 tracking-wide transition-colors border-b border-stone-50"><?php echo esc_html( $n['label'] ); ?></a>
		<?php endforeach; ?>
		<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="flex items-center gap-2 py-2.5 text-sm text-stone-700 hover:text-stone-900 tracking-wide transition-colors border-b border-stone-50">
			<?php dreamrugs_icon( 'shopping-cart', 14 ); ?> Cart<span id="drc-header-cart-mobile" data-drc-cart-count="<?php echo (int) $cart_count; ?>"><?php echo $cart_count > 0 ? ' (' . (int) $cart_count . ')' : ''; ?></span>
		</a>
		<?php if ( $is_logged_in ) : ?>
			<div class="pt-3 space-y-1">
				<div class="flex items-center gap-2.5 py-2 border-b border-stone-100">
					<div class="w-7 h-7 rounded-full bg-stone-100 flex items-center justify-center flex-shrink-0"><?php dreamrugs_icon( 'user', 12, 'text-stone-600' ); ?></div>
					<div class="min-w-0 flex-1">
						<p class="text-stone-900 text-sm font-medium truncate"><?php echo esc_html( $current_user->display_name ); ?></p>
						<p class="text-stone-400 text-xs truncate"><?php echo esc_html( $current_user->user_email ); ?></p>
					</div>
				</div>
				<a href="<?php echo esc_url( home_url( '/my-orders' ) ); ?>" class="flex items-center gap-3 py-2.5 text-sm text-stone-600 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'package', 13, 'text-stone-400' ); ?> My Orders</a>
				<a href="<?php echo esc_url( home_url( '/my-quotes' ) ); ?>" class="flex items-center gap-3 py-2.5 text-sm text-stone-600 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'file-text', 13, 'text-stone-400' ); ?> My Quotes</a>
				<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="flex items-center gap-3 py-2.5 text-sm text-stone-500 hover:text-red-600 transition-colors w-full"><?php dreamrugs_icon( 'log-out', 13 ); ?> Sign out</a>
			</div>
		<?php else : ?>
			<div class="pt-3"><a href="<?php echo esc_url( wp_login_url() ); ?>" class="text-center block py-2.5 text-sm text-stone-600 hover:text-stone-900 transition-colors">Sign In</a></div>
		<?php endif; ?>
	</div>
</header>

<main class="flex-1" :class="infoBarDismissed ? 'pt-[70px]' : 'pt-[102px]'">
