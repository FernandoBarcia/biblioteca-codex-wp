<?php
/**
 * Plugin Name: Biblioteca Codex
 * Description: Guarda tu biblioteca de lecturas en WordPress y la conecta con la app Biblioteca Codex para iPhone y iPad. Los libros son privados: solo se ven en el escritorio y en la app.
 * Version: 1.0.1
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Fernando Barcia
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: biblioteca-codex
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'BCODEX_FILE', __FILE__ );
define( 'BCODEX_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCODEX_VERSION', '1.0.1' );

// The full «Biblioteca» plugin keeps the same books and answers the same API. Beside it this one stays idle,
// so the two never register the library twice.
add_action( 'plugins_loaded', function () {
	if ( defined( 'FB_LIBRARY_FILE' ) ) {
		add_action( 'admin_notices', function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( 'Biblioteca Codex: ya tienes activo el plugin Biblioteca, que hace lo mismo y más. Puedes desactivar Biblioteca Codex.' ) . '</p></div>';
			}
		} );
		return;
	}
	require_once BCODEX_DIR . 'inc/rules.php';
	require_once BCODEX_DIR . 'inc/lookup.php';
	require_once BCODEX_DIR . 'inc/covers.php';
	require_once BCODEX_DIR . 'inc/admin.php';
	require_once BCODEX_DIR . 'inc/api.php';
} );
