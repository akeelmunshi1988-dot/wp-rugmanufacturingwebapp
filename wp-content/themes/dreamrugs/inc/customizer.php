<?php
/**
 * Theme Customizer: brand color and font-pairing controls. This is the one
 * piece of real visual configurability the theme was missing entirely (see
 * the ThemeForest-readiness plan) — every other admin-editable field (hero
 * copy, contact info, homepage sections, etc.) already goes through
 * DRC_Settings/the DreamRugs admin menu; colors and fonts were the only
 * things still hardcoded straight into compiled Tailwind output with no
 * admin control at all.
 *
 * Mechanism: a small, fixed set of CSS custom properties (--drc-primary,
 * --drc-accent, --drc-font-heading, --drc-font-body), written as an inline
 * <style> block in wp_head from the chosen theme_mods, consumed by the five
 * shared "storefront-*" component classes in src/input.css (compiled into
 * assets/style.build.css) and by tailwind.config.js's fontFamily. Buyers get
 * live-editable brand colors/fonts with zero Node/Tailwind rebuild — the
 * compiled CSS references var(--drc-primary, <today's exact default>), so
 * appearance is byte-identical until a buyer actually changes something in
 * the Customizer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Curated, not "any Google Font" — keeps the Customizer simple and keeps
 * every choice visually consistent with the theme's editorial design
 * language, matching Envato reviewers' general preference for opinionated,
 * bounded options over open-ended free-text theme settings. */
function dreamrugs_heading_fonts(): array {
	return array(
		'Cormorant Garamond' => array( 'google' => 'Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500', 'stack' => "'Cormorant Garamond', Georgia, Cambria, serif" ),
		'Playfair Display'   => array( 'google' => 'Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500', 'stack' => "'Playfair Display', Georgia, Cambria, serif" ),
		'Libre Baskerville'  => array( 'google' => 'Libre+Baskerville:ital,wght@0,400;0,700;1,400', 'stack' => "'Libre Baskerville', Georgia, Cambria, serif" ),
	);
}

function dreamrugs_body_fonts(): array {
	return array(
		'Inter'       => array( 'google' => 'Inter:wght@300;400;500;600', 'stack' => "'Inter', system-ui, sans-serif" ),
		'Work Sans'   => array( 'google' => 'Work+Sans:wght@300;400;500;600', 'stack' => "'Work Sans', system-ui, sans-serif" ),
		'Nunito Sans' => array( 'google' => 'Nunito+Sans:wght@300;400;500;600', 'stack' => "'Nunito Sans', system-ui, sans-serif" ),
	);
}

const DRC_DEFAULT_PRIMARY_COLOR = '#1c1917'; // Tailwind stone-900 — today's hardcoded button/heading color
const DRC_DEFAULT_ACCENT_COLOR  = '#a8a29e'; // Tailwind stone-400 — today's hardcoded eyebrow/muted-label color

function dreamrugs_sanitize_font_choice( $value, $control ) {
	$choices = $control->manager->get_control( $control->id )->choices;
	return array_key_exists( $value, $choices ) ? $value : $control->setting->default;
}

function dreamrugs_customize_register( WP_Customize_Manager $wp_customize ) {
	$wp_customize->add_section( 'dreamrugs_brand', array(
		'title'    => 'Brand Colors & Fonts',
		'priority' => 25,
	) );

	$wp_customize->add_setting( 'drc_color_primary', array(
		'default'           => DRC_DEFAULT_PRIMARY_COLOR,
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'postMessage',
	) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'drc_color_primary', array(
		'label'       => 'Primary Color',
		'description' => 'Buttons, headings, and links across the storefront.',
		'section'     => 'dreamrugs_brand',
	) ) );

	$wp_customize->add_setting( 'drc_color_accent', array(
		'default'           => DRC_DEFAULT_ACCENT_COLOR,
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'postMessage',
	) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'drc_color_accent', array(
		'label'       => 'Accent Color',
		'description' => 'Small uppercase section labels and muted text.',
		'section'     => 'dreamrugs_brand',
	) ) );

	$wp_customize->add_setting( 'drc_font_heading', array(
		'default'           => 'Cormorant Garamond',
		'sanitize_callback' => 'dreamrugs_sanitize_font_choice',
		'transport'         => 'refresh',
	) );
	$wp_customize->add_control( 'drc_font_heading', array(
		'label'   => 'Heading Font',
		'section' => 'dreamrugs_brand',
		'type'    => 'select',
		'choices' => array_combine( array_keys( dreamrugs_heading_fonts() ), array_keys( dreamrugs_heading_fonts() ) ),
	) );

	$wp_customize->add_setting( 'drc_font_body', array(
		'default'           => 'Inter',
		'sanitize_callback' => 'dreamrugs_sanitize_font_choice',
		'transport'         => 'refresh',
	) );
	$wp_customize->add_control( 'drc_font_body', array(
		'label'   => 'Body Font',
		'section' => 'dreamrugs_brand',
		'type'    => 'select',
		'choices' => array_combine( array_keys( dreamrugs_body_fonts() ), array_keys( dreamrugs_body_fonts() ) ),
	) );

	// Site Identity already has a logo control once add_theme_support('custom-logo')
	// is declared (functions.php) — nothing to add here, just noting why this
	// file doesn't duplicate it.
}
add_action( 'customize_register', 'dreamrugs_customize_register' );

/** Builds the single Google Fonts stylesheet URL for whichever heading/body
 * fonts are currently selected — replaces the old hardcoded two-family URL. */
function dreamrugs_google_fonts_url(): string {
	$heading = dreamrugs_heading_fonts()[ get_theme_mod( 'drc_font_heading', 'Cormorant Garamond' ) ] ?? dreamrugs_heading_fonts()['Cormorant Garamond'];
	$body    = dreamrugs_body_fonts()[ get_theme_mod( 'drc_font_body', 'Inter' ) ] ?? dreamrugs_body_fonts()['Inter'];
	// Oswald: fixed accent typeface for the homepage Company Values section's
	// `font-condensed` headline (tailwind.config.js) — not part of the
	// Customizer's swappable heading/body pair, so always loaded regardless
	// of which heading/body fonts are selected.
	$condensed = 'Oswald:wght@500;600';
	return 'https://fonts.googleapis.com/css2?family=' . $heading['google'] . '&family=' . $body['google'] . '&family=' . $condensed . '&display=swap';
}

/** Inline CSS variables reflecting the current theme_mods — consumed by the
 * storefront-* component classes (src/input.css) and tailwind.config.js's
 * fontFamily. Every var() usage downstream carries today's exact hardcoded
 * value as its own fallback, so this being empty/uncustomized changes
 * nothing about default appearance. */
function dreamrugs_brand_css_vars(): void {
	$primary = get_theme_mod( 'drc_color_primary', DRC_DEFAULT_PRIMARY_COLOR ) ?: DRC_DEFAULT_PRIMARY_COLOR;
	$accent  = get_theme_mod( 'drc_color_accent', DRC_DEFAULT_ACCENT_COLOR ) ?: DRC_DEFAULT_ACCENT_COLOR;
	$heading = dreamrugs_heading_fonts()[ get_theme_mod( 'drc_font_heading', 'Cormorant Garamond' ) ]['stack'] ?? dreamrugs_heading_fonts()['Cormorant Garamond']['stack'];
	$body    = dreamrugs_body_fonts()[ get_theme_mod( 'drc_font_body', 'Inter' ) ]['stack'] ?? dreamrugs_body_fonts()['Inter']['stack'];

	// Not esc_html(): this is CSS inside a <style> element, not HTML — entity-
	// encoding it (as esc_html() would turn the font stacks' single quotes
	// into &#039;) produces invalid CSS that browsers silently ignore, since
	// <style> content is never HTML-entity-decoded. $primary/$accent are
	// already constrained to a valid #hex or empty string by
	// sanitize_hex_color(); $heading/$body only ever come from this file's
	// own hardcoded font-stack arrays, never user input directly.
	$primary = sanitize_hex_color( $primary ) ?: DRC_DEFAULT_PRIMARY_COLOR;
	$accent  = sanitize_hex_color( $accent ) ?: DRC_DEFAULT_ACCENT_COLOR;
	?>
	<style id="dreamrugs-brand-vars">
		:root {
			--drc-primary: <?php echo $primary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS value, already constrained to #hex by sanitize_hex_color() above. ?>;
			--drc-accent: <?php echo $accent; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS value, already constrained to #hex by sanitize_hex_color() above. ?>;
			--drc-font-heading: <?php echo $heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS value from this file's own hardcoded font-stack array, not user input. ?>;
			--drc-font-body: <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS value from this file's own hardcoded font-stack array, not user input. ?>;
		}
	</style>
	<?php
}
add_action( 'wp_head', 'dreamrugs_brand_css_vars', 20 );

/** Live-preview: color pickers use 'postMessage' transport above, so without
 * this the Customizer preview iframe would only update on Save — this pushes
 * the two CSS vars straight into the iframe's <style id="dreamrugs-brand-vars">
 * as the picker moves. Fonts use 'refresh' transport instead (simpler; a
 * font-family/Google-Fonts-URL swap needs a real reload) so no JS wiring is
 * needed for those two controls. */
function dreamrugs_customize_preview_js() {
	wp_enqueue_script(
		'dreamrugs-customizer-preview',
		get_theme_file_uri( 'assets/customizer-preview.js' ),
		array( 'customize-preview' ),
		filemtime( get_theme_file_path( 'assets/customizer-preview.js' ) ),
		true
	);
}
add_action( 'customize_preview_init', 'dreamrugs_customize_preview_js' );
