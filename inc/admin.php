<?php
/**
 * The library in the dashboard: the books as a private kind of content, their details in the editor,
 * and a short page of settings. The books have no public page; they are seen here and in the app.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'init', function () {
	register_post_type( 'fb_book', array(
		'labels'              => array( 'name' => 'Biblioteca', 'singular_name' => 'Libro', 'add_new' => 'Añadir libro', 'add_new_item' => 'Añadir libro', 'edit_item' => 'Editar libro', 'search_items' => 'Buscar libros', 'not_found' => 'No hay libros.', 'featured_image' => 'Portada', 'set_featured_image' => 'Elegir portada', 'remove_featured_image' => 'Quitar portada', 'use_featured_image' => 'Usar como portada' ),
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_rest'        => false,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'menu_icon'           => 'dashicons-book-alt',
		'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ),
	) );
} );

/** The fields of the box «Tu lectura», in the order they are shown: key => label. */
function bcodex_editor_fields() {
	return array( 'author' => 'Autor', 'status' => 'Estado de lectura', 'format' => 'Tipo', 'started' => 'Fecha de inicio', 'finished' => 'Fecha de finalización', 'isbn' => 'ISBN', 'pages' => 'Páginas', 'volumes' => 'Tomos de la serie', 'volumes_read' => 'Leído hasta el tomo', 'series' => 'Saga o colección', 'grouped' => 'Agrupar la saga', 'position' => 'Orden en la saga', 'note' => 'Una frase sobre este libro', 'rating' => 'Veredicto', 'favorite' => 'Favorito', 'marks' => 'Distinciones', 'story' => 'Por qué es una obra maestra', 'cover' => 'URL de portada' );
}

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'bcodex-details', 'Tu lectura', 'bcodex_editor', 'fb_book', 'normal', 'high' );
} );

function bcodex_editor( $post ) {
	wp_nonce_field( 'bcodex_save', 'bcodex_nonce' );
	echo '<p>Solo hacen falta el título, el autor, el estado y el tipo. Lo demás es opcional. Estos mismos datos se ven y se cambian desde la app.</p>';
	echo '<table class="form-table" role="presentation">';
	foreach ( bcodex_editor_fields() as $key => $label ) {
		$value = (string) bcodex_meta( $post->ID, $key );
		$id = 'bcodex-' . $key;
		$name = 'bcodex[' . $key . ']';
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'status' === $key ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( bcodex_states() as $v => $text ) { echo '<option value="' . esc_attr( $v ) . '" ' . selected( $value ?: 'unread', $v, false ) . '>' . esc_html( $text ) . '</option>'; }
			echo '</select><p class="description">Al pasar a «Leyendo» o «Leído» se pone la fecha de hoy si la de inicio o la de finalización está vacía.</p>';
		} elseif ( 'format' === $key ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( bcodex_formats() as $v => $text ) { echo '<option value="' . esc_attr( $v ) . '" ' . selected( bcodex_format( $post->ID ), $v, false ) . '>' . esc_html( $text ) . '</option>'; }
			echo '</select>';
		} elseif ( 'rating' === $key ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">Sin valorar</option>';
			foreach ( bcodex_verdicts() as $v => $verdict ) { echo '<option value="' . esc_attr( (string) $v ) . '" ' . selected( bcodex_clean_rating( $value ), (string) $v, false ) . '>' . esc_html( $verdict[0] . ' — ' . $verdict[1] ) . '</option>'; }
			echo '</select>';
		} elseif ( in_array( $key, array( 'grouped', 'favorite' ), true ) ) {
			$text = 'grouped' === $key ? 'Mostrar junto a los títulos de la misma saga y tipo' : 'Uno de mis favoritos';
			echo '<label><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="checkbox" value="1" ' . checked( $value, '1', false ) . '> ' . esc_html( $text ) . '</label>';
		} elseif ( 'marks' === $key ) {
			$on = bcodex_book_marks( $post->ID );
			echo '<fieldset id="' . esc_attr( $id ) . '">';
			foreach ( bcodex_marks() as $slug => $mark ) { echo '<label style="display:block;margin:0 0 6px"><input name="bcodex[marks][]" type="checkbox" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $on, true ), true, false ) . '> <strong>' . esc_html( $mark[0] ) . '</strong> — ' . esc_html( $mark[1] ) . '</label>'; }
			echo '</fieldset>';
		} else {
			$type = in_array( $key, array( 'started', 'finished' ), true ) ? 'date' : ( in_array( $key, array( 'pages', 'volumes', 'volumes_read', 'position' ), true ) ? 'number' : ( 'cover' === $key ? 'url' : 'text' ) );
			echo '<input class="' . ( 'number' === $type || 'date' === $type ? 'regular-text' : 'large-text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $type ? ' min="0" step="1"' : '' ) . ( 'story' === $key ? ' maxlength="220"' : '' ) . '>';
			$help = array(
				'volumes'      => 'Para una serie larga en una sola ficha: cuántos tomos tiene. Déjalo vacío si esta ficha es un solo libro o tomo.',
				'volumes_read' => 'Cuántos tomos de la serie llevas leídos. Con el estado «Leído» cuentan todos.',
				'position'     => 'Opcional: 1, 2, 3… el lugar de este título dentro de su saga.',
				'story'        => 'Solo para las lecturas con el veredicto «Obra maestra»: una línea, hasta 220 caracteres.',
				'cover'        => 'Opcional. Al guardar, la imagen de esa dirección se descarga a Medios y pasa a ser la portada. La portada elegida en «Portada» tiene prioridad.',
			);
			if ( isset( $help[ $key ] ) ) { echo '<p class="description">' . esc_html( $help[ $key ] ) . '</p>'; }
		}
		echo '</td></tr>';
	}
	echo '</table>';
}

add_action( 'save_post_fb_book', function ( $id ) {
	if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ! current_user_can( 'edit_post', $id ) || ! isset( $_POST['bcodex_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bcodex_nonce'] ) ), 'bcodex_save' ) ) { return; }
	$data = isset( $_POST['bcodex'] ) && is_array( $_POST['bcodex'] ) ? wp_unslash( $_POST['bcodex'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is cleaned by bcodex_clean_value().
	$meta = array();
	foreach ( bcodex_editor_fields() as $key => $label ) {
		$meta[ $key ] = bcodex_clean_value( $key, 'marks' === $key ? ( $data['marks'] ?? array() ) : ( $data[ $key ] ?? '' ) );
	}
	// A record that stands for a whole series is not part of a saga.
	if ( absint( $meta['volumes'] ) >= 2 ) { $meta['series'] = ''; $meta['grouped'] = '0'; $meta['position'] = ''; }
	// An empty date counts as not given, so the state can fill it.
	foreach ( array( 'started', 'finished' ) as $date ) { if ( '' === $meta[ $date ] ) { unset( $meta[ $date ] ); delete_post_meta( $id, '_fb_book_' . $date ); } }
	bcodex_fill_dates( $id, $meta );
	foreach ( $meta as $key => $value ) { update_post_meta( $id, '_fb_book_' . $key, $value ); }
	if ( bcodex_has_linked_cover( $id ) ) {
		$result = bcodex_import_cover( $id );
		if ( is_wp_error( $result ) ) { set_transient( 'bcodex_cover_failed_' . get_current_user_id(), get_the_title( $id ) . ': ' . $result->get_error_message(), 60 ); }
	}
} );

add_action( 'admin_notices', function () {
	$key = 'bcodex_cover_failed_' . get_current_user_id();
	$message = get_transient( $key );
	if ( $message ) {
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( 'No se ha podido descargar la portada de ' . $message ) . '</p></div>';
	}
} );

// The list of books shows who wrote each one and where its reading stands.
add_filter( 'manage_fb_book_posts_columns', function ( $columns ) {
	$date = $columns['date'] ?? null;
	unset( $columns['date'] );
	$columns += array( 'bcodex_author' => 'Autor', 'bcodex_format' => 'Tipo', 'bcodex_status' => 'Lectura', 'bcodex_verdict' => 'Veredicto' );
	if ( $date ) { $columns['date'] = $date; }
	return $columns;
} );
add_action( 'manage_fb_book_posts_custom_column', function ( $column, $id ) {
	if ( 'bcodex_author' === $column ) { echo esc_html( (string) bcodex_meta( $id, 'author' ) ); }
	if ( 'bcodex_format' === $column ) { echo esc_html( bcodex_formats()[ bcodex_format( $id ) ] ); }
	if ( 'bcodex_status' === $column ) { echo esc_html( bcodex_states()[ bcodex_meta( $id, 'status' ) ] ?? 'Sin clasificar' ); }
	if ( 'bcodex_verdict' === $column ) { $rating = bcodex_clean_rating( bcodex_meta( $id, 'rating' ) ); echo esc_html( '' === $rating ? '—' : bcodex_verdicts()[ $rating ][0] ); }
}, 10, 2 );

/** Books to try the app with. Each is array( title, author, format, state, isbn, pages, rating, started, finished ). */
function bcodex_sample_books() {
	$year = wp_date( 'Y' );
	return array(
		array( 'The Fellowship of the Ring', 'J.R.R. Tolkien', 'book', 'read', '9780261103573', 407, '5', $year . '-01-08', $year . '-01-29' ),
		array( 'Dune', 'Frank Herbert', 'book', 'read', '9780441172719', 896, '4.5', $year . '-02-03', $year . '-03-01' ),
		array( '1984', 'George Orwell', 'book', 'read', '9780451524935', 328, '4', '', ( $year - 1 ) . '-11-20' ),
		array( 'Pride and Prejudice', 'Jane Austen', 'book', 'read', '9780141439518', 435, '3.5', '', ( $year - 1 ) . '-06-14' ),
		array( 'Cien años de soledad', 'Gabriel García Márquez', 'book', 'read', '9788497592208', 496, '5.5', '', ( $year - 2 ) . '-08-30' ),
		array( 'The Little Prince', 'Antoine de Saint-Exupéry', 'book', 'read', '9780156012195', 96, '4', $year . '-03-10', $year . '-03-11' ),
		array( 'The Way of Kings', 'Brandon Sanderson', 'book', 'reading', '9780765326355', 1008, '', $year . '-04-02', '' ),
		array( 'A Game of Thrones', 'George R. R. Martin', 'book', 'unread', '9780007448036', 801, '', '', '' ),
		array( 'Harry Potter and the Philosopher\'s Stone', 'J. K. Rowling', 'book', 'unread', '9780747532743', 223, '', '', '' ),
		array( 'Death Note, Vol. 1', 'Tsugumi Ohba, Takeshi Obata', 'manga', 'read', '9781421501680', 221, '4', '', $year . '-02-15' ),
		array( 'Attack on Titan 1', 'Hajime Isayama', 'manga', 'reading', '9781612620244', 208, '', $year . '-04-10', '' ),
		array( 'One Piece, Vol. 1', 'Eiichiro Oda', 'manga', 'wish', '9781421536255', 216, '', '', '' ),
		array( 'Demon Slayer, Vol. 1', 'Koyoharu Gotouge', 'manga', 'wish', '9781974700523', 192, '', '', '' ),
	);
}

/** Adds the sample books to a user's library, skipping the ISBNs that user already has. Returns how many were added. */
function bcodex_add_sample_books( $user ) {
	$have = array();
	foreach ( get_posts( array( 'post_type' => 'fb_book', 'post_status' => 'any', 'author' => $user, 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) as $id ) { $have[ bcodex_clean_isbn( bcodex_meta( $id, 'isbn' ) ) ] = true; }
	$added = 0;
	foreach ( bcodex_sample_books() as $book ) {
		list( $title, $author, $format, $state, $isbn, $pages, $rating, $started, $finished ) = $book;
		if ( isset( $have[ $isbn ] ) ) { continue; }
		$id = wp_insert_post( array( 'post_type' => 'fb_book', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => $user ), true );
		if ( is_wp_error( $id ) ) { continue; }
		// The cover stays an address at Open Library: nothing is downloaded until the book is edited.
		$meta = array( 'author' => $author, 'format' => $format, 'status' => $state, 'isbn' => $isbn, 'pages' => $pages, 'rating' => $rating, 'started' => $started, 'finished' => $finished, 'cover' => 'https://covers.openlibrary.org/b/isbn/' . $isbn . '-L.jpg' );
		foreach ( $meta as $key => $value ) { update_post_meta( $id, '_fb_book_' . $key, bcodex_clean_value( $key, $value ) ); }
		$added++;
	}
	return $added;
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=fb_book', 'Ajustes de Biblioteca Codex', 'Ajustes', 'manage_options', 'bcodex-settings', 'bcodex_settings_page' );
} );

function bcodex_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$notice = '';
	if ( isset( $_POST['bcodex_settings_save'] ) ) {
		check_admin_referer( 'bcodex_settings' );
		$scope = isset( $_POST['bcodex_goal_scope'] ) ? sanitize_key( wp_unslash( $_POST['bcodex_goal_scope'] ) ) : 'all';
		update_option( 'bcodex_settings', array(
			'goal'       => isset( $_POST['bcodex_goal'] ) ? min( 500, absint( wp_unslash( $_POST['bcodex_goal'] ) ) ) : 0,
			'goal_scope' => in_array( $scope, array( 'all', 'book', 'manga' ), true ) ? $scope : 'all',
			'google_key' => isset( $_POST['bcodex_google_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bcodex_google_key'] ) ) : '',
		), false );
		$notice = 'Ajustes guardados.';
	}
	if ( isset( $_POST['bcodex_samples_add'] ) ) {
		check_admin_referer( 'bcodex_samples' );
		$user = isset( $_POST['bcodex_samples_user'] ) ? absint( wp_unslash( $_POST['bcodex_samples_user'] ) ) : 0;
		if ( $user && get_userdata( $user ) && user_can( $user, 'edit_posts' ) ) {
			$added = bcodex_add_sample_books( $user );
			$notice = $added ? sprintf( 'Se han añadido %d libros de ejemplo.', $added ) : 'Ese usuario ya tenía todos los libros de ejemplo.';
		} else { $notice = 'Elige un usuario que pueda escribir entradas.'; }
	}
	echo '<div class="wrap"><h1>Ajustes de Biblioteca Codex</h1>';
	if ( $notice ) { echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>'; }

	echo '<h2>Conectar la app</h2><ol>';
	echo '<li>En <a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">tu perfil</a>, crea una contraseña de aplicación llamada «Biblioteca Codex».</li>';
	echo '<li>En la app, abre Ajustes → Conexión con WordPress y escribe esta dirección, tu usuario y esa contraseña:</li></ol>';
	echo '<p><code>' . esc_html( home_url() ) . '</code></p>';
	if ( ! is_ssl() ) { echo '<div class="notice notice-warning inline"><p>Esta web no usa HTTPS. La app solo se conecta a direcciones seguras (https).</p></div>'; }
	if ( ! wp_is_application_passwords_available() ) { echo '<div class="notice notice-warning inline"><p>Las contraseñas de aplicación están desactivadas en esta web, quizá por un plugin de seguridad. La app las necesita.</p></div>'; }

	echo '<h2>Lectura</h2><form method="post">';
	wp_nonce_field( 'bcodex_settings' );
	$scope = (string) bcodex_setting( 'goal_scope', 'all' );
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th scope="row"><label for="bcodex_goal">Objetivo de lecturas del año</label></th><td><input id="bcodex_goal" name="bcodex_goal" type="number" min="0" max="500" step="1" value="' . esc_attr( (string) absint( bcodex_setting( 'goal', 0 ) ) ) . '"><p class="description">Con 0 no hay objetivo. Cuentan los libros leídos este año y las series de manga completas; los tomos sueltos no suman.</p></td></tr>';
	echo '<tr><th scope="row"><label for="bcodex_goal_scope">Qué cuenta</label></th><td><select id="bcodex_goal_scope" name="bcodex_goal_scope">';
	foreach ( array( 'all' => 'Todo', 'book' => 'Solo libros', 'manga' => 'Solo manga' ) as $v => $text ) { echo '<option value="' . esc_attr( $v ) . '" ' . selected( $scope, $v, false ) . '>' . esc_html( $text ) . '</option>'; }
	echo '</select></td></tr>';
	echo '<tr><th scope="row"><label for="bcodex_google_key">Clave de Google Books</label></th><td><input class="regular-text" id="bcodex_google_key" name="bcodex_google_key" type="text" autocomplete="off" value="' . esc_attr( (string) bcodex_setting( 'google_key', '' ) ) . '"><p class="description">Opcional. Los libros se buscan en Open Library; con una clave propia de la Books API de Google se busca también en Google Books.</p></td></tr>';
	echo '</table>';
	submit_button( 'Guardar ajustes', 'primary', 'bcodex_settings_save' );
	echo '</form>';

	echo '<h2>Libros de ejemplo</h2><p>Añade trece libros y mangas de ejemplo para probar la app: leídos con su veredicto, en lectura, pendientes y en la desiderata. Los que ese usuario ya tenga no se repiten.</p><form method="post">';
	wp_nonce_field( 'bcodex_samples' );
	wp_dropdown_users( array( 'name' => 'bcodex_samples_user', 'capability' => 'edit_posts', 'selected' => get_current_user_id() ) );
	echo ' ';
	submit_button( 'Añadir libros de ejemplo', 'secondary', 'bcodex_samples_add', false );
	echo '</form></div>';
}
