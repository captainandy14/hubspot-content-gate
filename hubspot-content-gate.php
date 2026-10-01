<?php
/**
 * Plugin Name: HubSpot Content Gate
 * Description: Gates article bodies behind a HubSpot form, site-wide via the_content. Configurable from Settings → Content Gate.
 * Version:     2.2
 * Author:      Anando DasGupta and Claude Anthropic
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================== *
 *  Settings storage + defaults
 * ================================================================== */
const HSG_OPTION = 'hsg_settings';

function hsg_defaults() {
	return array(
		'enabled'           => 1,
		'portal_id'         => '',
		'form_id'           => '',
		'region'            => 'na1',
		'post_types'        => array( 'post' ),
		'gate_categories'   => array(),
		'teaser_words'      => 120,
		'min_words'         => 200,
		'gate_logged_in'    => 0,
		'load_script'       => 1,
		'hide_when_gated'   => '',
		'prompt'            => 'Enter your email to keep reading.',
	);
}

function hsg_get_settings() {
	return wp_parse_args( get_option( HSG_OPTION, array() ), hsg_defaults() );
}

/* ================================================================== *
 *  Front end: gate the content
 * ================================================================== */

// Post-level eligibility: is THIS post one we should gate? Deliberately has
// NO in_the_loop / is_main_query guards — the wrapper below handles context.
function hsg_is_eligible() {
	$o = hsg_get_settings();

	if ( empty( $o['enabled'] ) ) {
		return false;
	}
	if ( ! is_singular( $o['post_types'] ) ) {
		return false;
	}
	if ( is_user_logged_in() && empty( $o['gate_logged_in'] ) ) {
		return false;
	}
	// Per-post exemption checkbox.
	if ( get_post_meta( get_the_ID(), '_hsg_disable', true ) ) {
		return false;
	}
	// Category filter — applies ONLY to post types that use the standard
	// 'category' taxonomy (e.g. posts). Post types without categories
	// (custom post types) are gated on post-type membership alone,
	// since there are no categories to filter by.
	if ( is_object_in_taxonomy( get_post_type(), 'category' ) ) {
		if ( ! empty( $o['gate_categories'] ) ) {
			if ( ! has_category( array_map( 'intval', (array) $o['gate_categories'] ), get_the_ID() ) ) {
				return false;
			}
		} else {
			// Category-using type, but no categories selected: gate nothing.
			return false;
		}
	}
	// Needs a portal + form to do anything.
	if ( $o['portal_id'] === '' || $o['form_id'] === '' ) {
		return false;
	}
	return true;
}

// Filter-context wrapper: only gate the page's PRIMARY post render.
// We deliberately avoid in_the_loop()/is_main_query() here: page builders
// like Elementor render the main post OUTSIDE the standard loop, so those
// checks wrongly return false. Matching the filtered post against the page's
// queried object is robust across the theme loop AND builder widgets, while
// still excluding secondary posts pulled into related lists, sidebars, etc.
function hsg_should_gate() {
	if ( is_admin() || is_feed() ) {
		return false;
	}
	if ( get_the_ID() !== get_queried_object_id() ) {
		return false;
	}
	return hsg_is_eligible();
}

function hsg_gate_content( $content ) {
	if ( ! hsg_should_gate() ) {
		return $content;
	}
	return hsg_build_gate( $content );
}

// Counts visible words in a block of HTML (tags stripped).
function hsg_word_count( $html ) {
	$text = trim( wp_strip_all_tags( (string) $html ) );
	if ( $text === '' ) {
		return 0;
	}
	return count( preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY ) );
}

// Builds the gate WITHOUT splitting the HTML. Page builders (Elementor) nest
// content many <div>s deep, so cutting the markup string breaks the tree. We
// instead output the full, intact content wrapped in a container, then trim it
// in the browser (where the DOM is valid): walk the rendered text to the word
// target and clip the container to that height with a fade. Robust to any markup.
function hsg_build_gate( $content ) {
	static $done = array();
	$id = get_the_ID();
	if ( isset( $done[ $id ] ) ) {
		return $content; // already gated this post this request; don't double up
	}

	$o = hsg_get_settings();

	// Don't gate posts shorter than the minimum word count.
	if ( hsg_word_count( $content ) < (int) $o['min_words'] ) {
		return $content;
	}

	$done[ $id ] = true;
	$target_id   = 'hsg-form-' . get_the_ID();

	ob_start();
	?>
	<div class="hsg-content"><?php echo $content; // phpcs:ignore ?></div>

	<div class="hsg-gate" id="hsg-gate">
		<p class="hsg-prompt"><?php echo esc_html( $o['prompt'] ); ?></p>
		<div id="<?php echo esc_attr( $target_id ); ?>"></div>
	</div>

	<style>
	.hsg-content.hsg-clipped { position: relative; overflow: hidden; }
	.hsg-content.hsg-clipped::after {
		content: ""; position: absolute; left: 0; right: 0; bottom: 0;
		height: 6em; pointer-events: none;
		background: linear-gradient( to bottom, rgba(255,255,255,0), #ffffff );
	}
	.hsg-hidden-by-gate { display: none !important; }
	</style>

	<script>
	(function () {
		var TEASER_WORDS   = <?php echo (int) $o['teaser_words']; ?>;
		var HIDE_SELECTORS = <?php echo wp_json_encode( $o['hide_when_gated'] ); ?>;

		function getContent() { return document.querySelector('.hsg-content'); }
		function getGate()    { return document.getElementById('hsg-gate'); }

		// Hide/show elements that live OUTSIDE the content wrapper (e.g. a
		// table-of-contents) while the wall is up, so they don't leak or link
		// into gated sections. Uses a class so original inline styles survive.
		function setHidden( hide ) {
			if ( ! HIDE_SELECTORS ) { return; }
			var sels = HIDE_SELECTORS.split(',');
			for ( var i = 0; i < sels.length; i++ ) {
				var sel = sels[i].replace(/^\s+|\s+$/g, '');
				if ( ! sel ) { continue; }
				var nodes;
				try { nodes = document.querySelectorAll(sel); } catch (e) { continue; }
				for ( var j = 0; j < nodes.length; j++ ) {
					if ( hide ) { nodes[j].classList.add('hsg-hidden-by-gate'); }
					else        { nodes[j].classList.remove('hsg-hidden-by-gate'); }
				}
			}
		}

		function unlock() {
			var c = getContent(), g = getGate();
			if (c) { c.classList.remove('hsg-clipped'); c.style.maxHeight = ''; }
			if (g) { g.style.display = 'none'; }
			setHidden( false );
			try { localStorage.setItem('hsg_unlocked', '1'); } catch (e) {}
		}

		// Any HubSpot form submission on the page (including this gate) unlocks.
		window.addEventListener('message', function (event) {
			if (event.data && event.data.type === 'hsFormCallback'
				&& event.data.eventName === 'onFormSubmitted') {
				unlock();
			}
		});

		// Clip the content to the word target, at the height of the element
		// containing the Nth word. Returns false if the post is shorter than
		// the target (nothing to gate).
		function clip() {
			var c = getContent();
			if (!c) { return false; }
			var walker = document.createTreeWalker(c, NodeFilter.SHOW_TEXT, null);
			var count = 0, node, cut = null;
			while ( (node = walker.nextNode()) ) {
				var m = node.textContent.match(/\S+/g);
				count += m ? m.length : 0;
				if (count >= TEASER_WORDS) { cut = node; break; }
			}
			if (!cut || !cut.parentElement) { return false; }
			var top    = c.getBoundingClientRect().top;
			var bottom = cut.parentElement.getBoundingClientRect().bottom;
			var h      = Math.max( Math.round( bottom - top ), 80 );
			c.style.maxHeight = h + 'px';
			c.classList.add('hsg-clipped');
			return true;
		}

		function buildForm() {
			var tries = 0;
			(function waitForHubSpot() {
				if (window.hbspt && window.hbspt.forms) {
					hbspt.forms.create({
						region:   <?php echo wp_json_encode( $o['region'] ); ?>,
						portalId: <?php echo wp_json_encode( $o['portal_id'] ); ?>,
						formId:   <?php echo wp_json_encode( $o['form_id'] ); ?>,
						target:   '#' + <?php echo wp_json_encode( $target_id ); ?>,
						onFormSubmitted: unlock
					});
					return;
				}
				if (tries++ < 100) { setTimeout(waitForHubSpot, 100); } // ~10s max
			})();
		}

		function init() {
			var g = getGate();
			// Returning subscriber: keep everything open, hide the gate.
			// (localStorage can throw in some privacy modes, so guard it.)
			var unlocked = false;
			try { unlocked = localStorage.getItem('hsg_unlocked') === '1'; } catch (e) {}
			if (unlocked) {
				if (g) { g.style.display = 'none'; }
				return;
			}
			// Too short to gate: leave open, hide the gate.
			if (!clip()) {
				if (g) { g.style.display = 'none'; }
				return;
			}
			// Wall is up: hide extras (e.g. table of contents) that sit
			// outside the content wrapper and would otherwise leak sections.
			setHidden( true );
			buildForm();
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', init);
		} else {
			init();
		}
	})();
	</script>
	<?php
	return ob_get_clean();
}
add_filter( 'the_content', 'hsg_gate_content' );

function hsg_enqueue() {
	$o = hsg_get_settings();
	if ( ! empty( $o['enabled'] ) && ! empty( $o['load_script'] ) && is_singular( $o['post_types'] ) ) {
		wp_enqueue_script( 'hsforms', 'https://js.hsforms.net/forms/embed/v2.js', array(), null, true );
	}
}
add_action( 'wp_enqueue_scripts', 'hsg_enqueue' );

/* ================================================================== *
 *  Admin: Settings → Content Gate
 * ================================================================== */
function hsg_admin_menu() {
	add_options_page(
		'HubSpot Content Gate',
		'Content Gate',
		'manage_options',
		'hsg-settings',
		'hsg_settings_page'
	);
}
add_action( 'admin_menu', 'hsg_admin_menu' );

function hsg_register_settings() {
	register_setting( 'hsg_group', HSG_OPTION, 'hsg_sanitize' );

	add_settings_section( 'hsg_connection', 'HubSpot connection', '__return_false', 'hsg-settings' );
	add_settings_field( 'portal_id', 'Portal ID', 'hsg_field_text', 'hsg-settings', 'hsg_connection', array( 'key' => 'portal_id' ) );
	add_settings_field( 'form_id', 'Form ID', 'hsg_field_text', 'hsg-settings', 'hsg_connection', array( 'key' => 'form_id' ) );
	add_settings_field( 'region', 'Region', 'hsg_field_region', 'hsg-settings', 'hsg_connection' );

	add_settings_section( 'hsg_behavior', 'Gating behavior', '__return_false', 'hsg-settings' );
	add_settings_field( 'enabled', 'Gate enabled', 'hsg_field_checkbox', 'hsg-settings', 'hsg_behavior', array( 'key' => 'enabled', 'label' => 'Turn the gate on across the site' ) );
	add_settings_field( 'post_types', 'Gate these post types', 'hsg_field_post_types', 'hsg-settings', 'hsg_behavior' );
	add_settings_field( 'gate_categories', 'Gate these categories', 'hsg_field_categories', 'hsg-settings', 'hsg_behavior' );
	add_settings_field( 'teaser_words', 'Free words', 'hsg_field_number', 'hsg-settings', 'hsg_behavior', array( 'key' => 'teaser_words', 'desc' => 'Approx. words shown before the gate (the teaser is cut at the end of the element containing the last free word).' ) );
	add_settings_field( 'min_words', 'Minimum length', 'hsg_field_number', 'hsg-settings', 'hsg_behavior', array( 'key' => 'min_words', 'desc' => 'Do not gate posts shorter than this many words.' ) );
	add_settings_field( 'gate_logged_in', 'Logged-in users', 'hsg_field_checkbox', 'hsg-settings', 'hsg_behavior', array( 'key' => 'gate_logged_in', 'label' => 'Also gate logged-in users (default: they read freely)' ) );
	add_settings_field( 'load_script', 'Load HubSpot script', 'hsg_field_checkbox', 'hsg-settings', 'hsg_behavior', array( 'key' => 'load_script', 'label' => 'Let this plugin load the HubSpot forms script (turn OFF if GTM or another tool already loads it)' ) );

	add_settings_section( 'hsg_content', 'Content', '__return_false', 'hsg-settings' );
	add_settings_field( 'prompt', 'Prompt text', 'hsg_field_text', 'hsg-settings', 'hsg_content', array( 'key' => 'prompt', 'wide' => true ) );
	add_settings_field( 'hide_when_gated', 'Hide while gated', 'hsg_field_text', 'hsg-settings', 'hsg_content', array( 'key' => 'hide_when_gated', 'wide' => true, 'desc' => 'Comma-separated CSS selectors to hide while the wall is up (e.g. a table of contents). Restored on unlock.' ) );
}
add_action( 'admin_init', 'hsg_register_settings' );

/* ---- field renderers ---- */
function hsg_field_text( $args ) {
	$o    = hsg_get_settings();
	$key  = $args['key'];
	$size = ! empty( $args['wide'] ) ? 'large-text' : 'regular-text';
	printf(
		'<input type="text" name="%s[%s]" value="%s" class="%s" />',
		esc_attr( HSG_OPTION ), esc_attr( $key ), esc_attr( $o[ $key ] ), esc_attr( $size )
	);
	if ( ! empty( $args['desc'] ) ) {
		printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
	}
}

function hsg_field_number( $args ) {
	$o   = hsg_get_settings();
	$key = $args['key'];
	printf(
		'<input type="number" min="0" name="%s[%s]" value="%s" class="small-text" />',
		esc_attr( HSG_OPTION ), esc_attr( $key ), esc_attr( $o[ $key ] )
	);
	if ( ! empty( $args['desc'] ) ) {
		printf( ' <span class="description">%s</span>', esc_html( $args['desc'] ) );
	}
}

function hsg_field_checkbox( $args ) {
	$o   = hsg_get_settings();
	$key = $args['key'];
	printf(
		'<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
		esc_attr( HSG_OPTION ), esc_attr( $key ), checked( ! empty( $o[ $key ] ), true, false ), esc_html( $args['label'] )
	);
}

function hsg_field_region() {
	$o = hsg_get_settings();
	echo '<select name="' . esc_attr( HSG_OPTION ) . '[region]">';
	foreach ( array( 'na1' => 'na1 (US)', 'eu1' => 'eu1 (EU)' ) as $val => $label ) {
		printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $o['region'], $val, false ), esc_html( $label ) );
	}
	echo '</select>';
}

function hsg_field_post_types() {
	$o     = hsg_get_settings();
	$types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'] );
	foreach ( $types as $type ) {
		printf(
			'<label style="margin-right:14px"><input type="checkbox" name="%s[post_types][]" value="%s" %s /> %s</label>',
			esc_attr( HSG_OPTION ),
			esc_attr( $type->name ),
			checked( in_array( $type->name, (array) $o['post_types'], true ), true, false ),
			esc_html( $type->labels->name )
		);
	}
}

function hsg_field_categories() {
	$o    = hsg_get_settings();
	$cats = get_categories( array( 'hide_empty' => false ) );
	if ( empty( $cats ) ) {
		echo '<p class="description">No categories found.</p>';
		return;
	}
	$selected = array_map( 'intval', (array) $o['gate_categories'] );
	echo '<div style="max-height:220px;overflow:auto;border:1px solid #ccd0d4;padding:8px 10px;max-width:320px">';
	foreach ( $cats as $cat ) {
		printf(
			'<label style="display:block;margin:2px 0"><input type="checkbox" name="%s[gate_categories][]" value="%d" %s /> %s</label>',
			esc_attr( HSG_OPTION ),
			(int) $cat->term_id,
			checked( in_array( (int) $cat->term_id, $selected, true ), true, false ),
			esc_html( $cat->name )
		);
	}
	echo '</div>';
	echo '<p class="description">Only posts in a ticked category are gated. Tick none to gate nothing.</p>';
}

function hsg_sanitize( $input ) {
	$d   = hsg_defaults();
	$out = array();

	$out['enabled']           = empty( $input['enabled'] ) ? 0 : 1;
	$out['gate_logged_in']    = empty( $input['gate_logged_in'] ) ? 0 : 1;
	$out['load_script']       = empty( $input['load_script'] ) ? 0 : 1;
	$out['portal_id']         = sanitize_text_field( $input['portal_id'] ?? '' );
	$out['form_id']           = sanitize_text_field( $input['form_id'] ?? '' );
	$out['region']            = in_array( $input['region'] ?? '', array( 'na1', 'eu1' ), true ) ? $input['region'] : 'na1';
	$out['teaser_words']      = max( 0, (int) ( $input['teaser_words'] ?? $d['teaser_words'] ) );
	$out['min_words']         = max( 0, (int) ( $input['min_words'] ?? $d['min_words'] ) );
	$out['prompt']            = sanitize_text_field( $input['prompt'] ?? $d['prompt'] );
	$out['hide_when_gated']   = sanitize_text_field( $input['hide_when_gated'] ?? $d['hide_when_gated'] );

	$valid_types        = array_keys( get_post_types( array( 'public' => true ) ) );
	$out['post_types']  = array_values( array_intersect( (array) ( $input['post_types'] ?? array() ), $valid_types ) );
	if ( empty( $out['post_types'] ) ) {
		$out['post_types'] = array( 'post' );
	}

	$raw_cats            = array_map( 'intval', (array) ( $input['gate_categories'] ?? array() ) );
	$out['gate_categories'] = array();
	foreach ( $raw_cats as $cid ) {
		if ( $cid > 0 && get_term( $cid, 'category' ) instanceof WP_Term ) {
			$out['gate_categories'][] = $cid;
		}
	}

	return $out;
}

function hsg_settings_page() {
	?>
	<div class="wrap">
		<h1>HubSpot Content Gate</h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'hsg_group' );
			do_settings_sections( 'hsg-settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

// "Settings" link on the Plugins list row.
function hsg_action_links( $links ) {
	$url = admin_url( 'options-general.php?page=hsg-settings' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'hsg_action_links' );

/* ================================================================== *
 *  Per-post exemption checkbox
 * ================================================================== */
function hsg_add_metabox() {
	$o = hsg_get_settings();
	foreach ( (array) $o['post_types'] as $type ) {
		add_meta_box( 'hsg_meta', 'Content Gate', 'hsg_metabox', $type, 'side' );
	}
}
add_action( 'add_meta_boxes', 'hsg_add_metabox' );

function hsg_metabox( $post ) {
	wp_nonce_field( 'hsg_meta_save', 'hsg_meta_nonce' );
	$off = get_post_meta( $post->ID, '_hsg_disable', true );
	printf(
		'<label><input type="checkbox" name="hsg_disable" value="1" %s /> %s</label>',
		checked( $off, '1', false ),
		esc_html__( 'Do not gate this post (show full content free)' )
	);
}

function hsg_save_meta( $post_id ) {
	if ( ! isset( $_POST['hsg_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hsg_meta_nonce'] ) ), 'hsg_meta_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! empty( $_POST['hsg_disable'] ) ) {
		update_post_meta( $post_id, '_hsg_disable', '1' );
	} else {
		delete_post_meta( $post_id, '_hsg_disable' );
	}
}
add_action( 'save_post', 'hsg_save_meta' );
