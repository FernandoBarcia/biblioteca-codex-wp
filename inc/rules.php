<?php
/**
 * What a book is and what each of its fields may hold. The names of the post type (`fb_book`), of its fields (`_fb_book_*`)
 * and of the API (`fb-books/v1`) are the ones the app and the full «Biblioteca» plugin use, so a library moves between them untouched.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function bcodex_states() {
	return array( 'read' => 'Leído', 'reading' => 'Leyendo', 'unread' => 'Pendiente', 'paused' => 'En pausa', 'wish' => 'En desiderata', 'unknown' => 'Sin clasificar' );
}
function bcodex_formats() { return array( 'book' => 'Libro', 'manga' => 'Manga' ); }
function bcodex_meta( $id, $key ) { return get_post_meta( $id, '_fb_book_' . $key, true ); }

/** The plugin's own settings: the yearly goal and the Google Books key. */
function bcodex_setting( $key, $default = '' ) {
	$settings = get_option( 'bcodex_settings', array() );
	return is_array( $settings ) && array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
}

/**
 * The books a user may see: all of them for whoever can edit other people's posts, and only their own for an author or a
 * contributor. Every list the API answers with is built from this, so nobody reads another person's drafts.
 */
function bcodex_visible_ids() {
	global $bcodex_visible;
	$user = get_current_user_id();
	if ( ! isset( $bcodex_visible[ $user ] ) ) {
		$query = array( 'post_type' => 'fb_book', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true );
		if ( ! current_user_can( 'edit_others_posts' ) ) { $query['author'] = $user; }
		$bcodex_visible[ $user ] = array_map( 'intval', get_posts( $query ) );
		update_meta_cache( 'post', $bcodex_visible[ $user ] );
	}
	return $bcodex_visible[ $user ];
}
// The list is worked out once per request, and again after a book is added, binned or brought back.
foreach ( array( 'save_post_fb_book', 'trashed_post', 'untrashed_post', 'deleted_post' ) as $bcodex_hook ) {
	add_action( $bcodex_hook, function () { $GLOBALS['bcodex_visible'] = array(); } );
}

// Manga is recognised in a title with no type chosen; the saved choice always wins.
function bcodex_guess_format( $title, $author ) {
	$authors = 'Ohba|Obata|Sadamoto|Watsuki|Himekawa|Toriyama|Eiichiro Oda|Kishimoto|Rumiko Takahashi|Kentaro Miura|Isayama|Urasawa|Tezuka|Takehiko Inoue|Togashi|Araki|Tite Kubo|Arakawa|Fujimoto|Akutami|Gotouge|Horikoshi|Inio Asano|Junji Ito|Sui Ishida';
	return preg_match( '/\\b(' . $authors . ')\\b/iu', (string) $author ) || preg_match( '/\\bmanga\\b/iu', (string) $title ) ? 'manga' : 'book';
}
function bcodex_format( $id ) {
	$format = bcodex_meta( $id, 'format' );
	return isset( bcodex_formats()[ $format ] ) ? $format : bcodex_guess_format( get_the_title( $id ), bcodex_meta( $id, 'author' ) );
}

// The volume number written in a title, if any.
function bcodex_volume( $id ) {
	return preg_match( '/(\d{1,3})(?!.*\d)/', get_the_title( $id ), $m ) ? ( ltrim( $m[1], '0' ) ?: '0' ) : '';
}
// A long series can be one record: array( volumes read, volumes in all ). Empty for a single title.
function bcodex_volumes( $id ) {
	$total = absint( bcodex_meta( $id, 'volumes' ) );
	if ( $total < 2 ) { return array(); }
	return array( 'read' === bcodex_meta( $id, 'status' ) ? $total : min( $total, absint( bcodex_meta( $id, 'volumes_read' ) ) ), $total );
}
// Volumes read that a record stands for: each one read in a series kept in one record, one for any other read title.
function bcodex_tomes( $id ) {
	$volumes = bcodex_volumes( $id );
	return $volumes ? $volumes[0] : (int) ( 'read' === bcodex_meta( $id, 'status' ) );
}
// A manga record that is one volume of a series: it has a saga or a number, and is not a series kept in one record.
function bcodex_is_tome( $id ) {
	return 'manga' === bcodex_format( $id ) && ! bcodex_volumes( $id ) && ( '' !== trim( (string) bcodex_meta( $id, 'series' ) ) || '' !== bcodex_volume( $id ) );
}
// What the yearly goal counts: read books and complete series, never loose volumes.
function bcodex_counts_for_goal( $id ) {
	return 'read' === bcodex_meta( $id, 'status' ) && ! bcodex_is_tome( $id );
}

/**
 * The verdict on a reading, kept as a number in half steps: ten verdicts from 0.5 to 5, and 5.5 for «Obra maestra».
 * Each one is array( label, the line under it ). The plugin carries no pictures: the app draws each verdict with its own trophy or medal.
 */
function bcodex_verdicts() {
	return array(
		'5.5' => array( 'Obra maestra', 'De las que permanecen.' ),
		'5'   => array( 'Imprescindible', 'De mis favoritos.' ),
		'4.5' => array( 'Sobresaliente', 'Rozó lo más alto.' ),
		'4'   => array( 'Excelente', 'Me encantó.' ),
		'3.5' => array( 'Notable', 'Me dejó huella.' ),
		'3'   => array( 'Muy bueno', 'Lo disfruté.' ),
		'2.5' => array( 'Entretenido', 'Pasé un buen rato.' ),
		'2'   => array( 'Correcto', 'Cumplió, sin más.' ),
		'1.5' => array( 'Flojo', 'Le faltó algo.' ),
		'1'   => array( 'No conectó', 'No fue para mí.' ),
		'0.5' => array( 'Para olvidar', 'No lo recomiendo.' ),
	);
}
function bcodex_clean_rating( $value ) {
	$value = (float) str_replace( ',', '.', (string) $value );
	return $value > 0 ? (string) max( 0.5, min( 5.5, round( $value * 2 ) / 2 ) ) : '';
}
function bcodex_rating( $id ) { return (float) bcodex_clean_rating( bcodex_meta( $id, 'rating' ) ); }
function bcodex_is_masterpiece( $id ) { return bcodex_rating( $id ) > 5; }

/** Distinctions a reading can carry beside its verdict: slug => array( label, what it means ). */
function bcodex_marks() {
	return array(
		'surprise'  => array( 'Sorpresa', 'No esperaba tanto de este libro.' ),
		'devoured'  => array( 'De una sentada', 'No pude soltarlo.' ),
		'moving'    => array( 'Me emocionó', 'Me removió por dentro.' ),
		'funny'     => array( 'Me hizo reír', 'Con risas de verdad.' ),
		'twist'     => array( 'Final de infarto', 'El final me dejó sin palabras.' ),
		'quotes'    => array( 'Para subrayar', 'Lleno de frases para guardar.' ),
		'art'       => array( 'Dibujo de lujo', 'El dibujo, por sí solo, ya lo vale.' ),
		'edition'   => array( 'Edición preciosa', 'Como objeto, una joya.' ),
		'reread'    => array( 'Relectura', 'Lo he leído más de una vez.' ),
		'gift'      => array( 'Para regalar', 'Se lo recomendaría a cualquiera.' ),
		'slow'      => array( 'Arranque lento', 'Cuesta entrar, pero luego mejora.' ),
		'overrated' => array( 'Sobrevalorado', 'Esperaba más, por lo que se dice de él.' ),
		'dropped'   => array( 'Abandonado', 'No llegué a terminarlo.' ),
	);
}
function bcodex_clean_marks( $value ) {
	$sent = array_map( 'strval', is_array( $value ) ? $value : explode( ',', (string) $value ) );
	return implode( ',', array_intersect( array_keys( bcodex_marks() ), $sent ) );
}
function bcodex_book_marks( $id ) {
	return array_values( array_intersect( array_keys( bcodex_marks() ), array_filter( explode( ',', (string) bcodex_meta( $id, 'marks' ) ) ) ) );
}

/** How a series kept in one record is drawn on the shelf of the full plugin. Kept so a library moves between the two untouched. */
function bcodex_tome_layouts() { return array( 'clasico', 'recostados', 'torre', 'pila', 'encima', 'abanico' ); }

function bcodex_clean_date( $value ) {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ) { return ''; }
	$p = explode( '-', $value );
	return checkdate( (int) $p[1], (int) $p[2], (int) $p[0] ) ? $value : '';
}
function bcodex_clean_isbn( $value ) { return strtoupper( preg_replace( '/[^0-9Xx]/', '', (string) $value ) ); }

/** One rule per field, shared by the editor and the API: what each field holds once saved. */
function bcodex_clean_value( $key, $value ) {
	if ( 'marks' === $key ) { return bcodex_clean_marks( $value ); }
	$value = is_scalar( $value ) ? (string) $value : '';
	switch ( $key ) {
		case 'status':      return array_key_exists( $value, bcodex_states() ) ? $value : 'unknown';
		case 'format':      return array_key_exists( $value, bcodex_formats() ) ? $value : 'book';
		case 'started':
		case 'finished':    return bcodex_clean_date( $value );
		case 'cover':       return esc_url_raw( $value, array( 'http', 'https' ) );
		case 'grouped':
		case 'favorite':    return '1' === $value ? '1' : '0';
		case 'rating':      return bcodex_clean_rating( $value );
		case 'isbn':        return bcodex_clean_isbn( $value );
		case 'podium':      return '' === $value ? '' : (string) min( 5, max( 1, absint( $value ) ) );
		case 'last_cover':  return wp_attachment_is_image( absint( $value ) ) ? (string) absint( $value ) : '';
		case 'tome_layout': return in_array( $value, bcodex_tome_layouts(), true ) ? $value : '';
		case 'legend':      return absint( $value ) ? (string) absint( $value ) : '';
		case 'story':       return trim( mb_substr( sanitize_text_field( $value ), 0, 220 ) );
		case 'pages':
		case 'position':
		case 'volumes':
		case 'volumes_read': return '' === $value ? '' : absint( $value );
		default:            return sanitize_text_field( $value );
	}
}

/** As in the app: moving to «Leyendo» or «Leído» fills a start or finish date that was not sent and is empty with today's. */
function bcodex_fill_dates( $id, array &$meta ) {
	$state = $meta['status'] ?? null;
	$today = wp_date( 'Y-m-d' );
	if ( 'reading' === $state && ! isset( $meta['started'] ) && '' === (string) bcodex_meta( $id, 'started' ) ) { $meta['started'] = $today; }
	if ( 'read' === $state && ! isset( $meta['finished'] ) && '' === (string) bcodex_meta( $id, 'finished' ) ) { $meta['finished'] = $today; }
}

/** Sagas in use among the books the user sees, by name: what a new title can take from each. */
function bcodex_sagas() {
	$sagas = array();
	foreach ( bcodex_visible_ids() as $id ) {
		$name = trim( (string) bcodex_meta( $id, 'series' ) );
		if ( '' !== $name ) { $sagas[ $name ][] = $id; }
	}
	$out = array();
	foreach ( $sagas as $name => $ids ) {
		$authors = array(); $formats = array(); $last = 0; $grouped = 0;
		foreach ( $ids as $id ) {
			$author = trim( (string) bcodex_meta( $id, 'author' ) );
			$format = bcodex_format( $id );
			if ( '' !== $author ) { $authors[ $author ] = true; }
			$formats[ $format ] = ( $formats[ $format ] ?? 0 ) + 1;
			$position = (string) bcodex_meta( $id, 'position' );
			if ( '' === $position && 'manga' === $format ) { $position = bcodex_volume( $id ); }
			$last = max( $last, (int) $position );
			if ( '1' === (string) bcodex_meta( $id, 'grouped' ) ) { $grouped++; }
		}
		arsort( $formats );
		// An author is only suggested when the whole saga shares it.
		$out[] = array( 'name' => (string) $name, 'author' => 1 === count( $authors ) ? (string) array_key_first( $authors ) : '', 'format' => (string) array_key_first( $formats ), 'next' => $last ? $last + 1 : count( $ids ) + 1, 'grouped' => $grouped > 0 );
	}
	usort( $out, fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );
	return $out;
}
function bcodex_author_names() {
	$names = array();
	foreach ( bcodex_visible_ids() as $id ) {
		$name = trim( (string) bcodex_meta( $id, 'author' ) );
		if ( '' !== $name ) { $names[ $name ] = true; }
	}
	$names = array_keys( $names );
	natcasesort( $names );
	return array_values( $names );
}
