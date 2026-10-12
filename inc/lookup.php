<?php
/**
 * Looks books up by ISBN or by title on Open Library and, with a key of your own, on Google Books.
 * Only the ISBN or the title is sent. Nothing is saved here: the app shows what is found and the person decides.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function bcodex_fetch_json( $url ) {
	$response = wp_safe_remote_get( $url, array( 'timeout' => 8, 'user-agent' => 'BibliotecaCodex-WP/' . BCODEX_VERSION ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return array(); }
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : array();
}

// Google Books is asked only with a key saved in Biblioteca → Ajustes: without one its shared quota is used up most days.
function bcodex_google( $query ) {
	$key = trim( (string) bcodex_setting( 'google_key', '' ) );
	if ( '' === $key ) { return array(); }
	return bcodex_fetch_json( 'https://www.googleapis.com/books/v1/volumes?maxResults=8&printType=books&q=' . rawurlencode( $query ) . '&key=' . rawurlencode( $key ) );
}

/** Up to six editions with that title, from Google Books (with a key) or, when it has none, from Open Library. */
function bcodex_title_search( $title, $author = '' ) {
	$title = trim( sanitize_text_field( (string) $title ) );
	$author = trim( sanitize_text_field( (string) $author ) );
	if ( strlen( $title ) < 2 ) { return new WP_Error( 'fb_search', 'Escribe antes el título del libro.', array( 'status' => 400 ) ); }
	$books = array();
	$source = 'Google Books';
	$google = bcodex_google( 'intitle:' . $title . ( $author ? ' inauthor:' . $author : '' ) );
	foreach ( (array) ( $google['items'] ?? array() ) as $item ) {
		$info = (array) ( $item['volumeInfo'] ?? array() );
		$isbn = array_column( (array) ( $info['industryIdentifiers'] ?? array() ), 'identifier', 'type' );
		$books[] = array(
			'title'     => $info['title'] ?? '',
			'author'    => implode( ', ', array_filter( (array) ( $info['authors'] ?? array() ), 'is_string' ) ),
			'pages'     => $info['pageCount'] ?? 0,
			'isbn'      => $isbn['ISBN_13'] ?? $isbn['ISBN_10'] ?? '',
			'cover'     => str_replace( '&edge=curl', '', set_url_scheme( (string) ( $info['imageLinks']['thumbnail'] ?? '' ), 'https' ) ),
			'year'      => substr( (string) ( $info['publishedDate'] ?? '' ), 0, 4 ),
			'publisher' => $info['publisher'] ?? '',
		);
	}
	if ( ! $books ) {
		$source = 'Open Library';
		$open = bcodex_fetch_json( 'https://openlibrary.org/search.json?limit=8&fields=title,author_name,number_of_pages_median,isbn,cover_i,first_publish_year,publisher&q=' . rawurlencode( $title ) . ( $author ? '&author=' . rawurlencode( $author ) : '' ) );
		foreach ( (array) ( $open['docs'] ?? array() ) as $doc ) {
			$books[] = array(
				'title'     => $doc['title'] ?? '',
				'author'    => implode( ', ', array_filter( (array) ( $doc['author_name'] ?? array() ), 'is_string' ) ),
				'pages'     => $doc['number_of_pages_median'] ?? 0,
				'isbn'      => ( (array) ( $doc['isbn'] ?? array() ) )[0] ?? '',
				'cover'     => empty( $doc['cover_i'] ) ? '' : 'https://covers.openlibrary.org/b/id/' . absint( $doc['cover_i'] ) . '-L.jpg',
				'year'      => (string) ( $doc['first_publish_year'] ?? '' ),
				'publisher' => ( (array) ( $doc['publisher'] ?? array() ) )[0] ?? '',
			);
		}
	}
	$clean = array();
	foreach ( $books as $book ) {
		$isbn = bcodex_clean_isbn( $book['isbn'] );
		$entry = array(
			'title'     => sanitize_text_field( (string) $book['title'] ),
			'author'    => sanitize_text_field( (string) $book['author'] ),
			'pages'     => absint( $book['pages'] ) ? (string) absint( $book['pages'] ) : '',
			'isbn'      => in_array( strlen( $isbn ), array( 10, 13 ), true ) ? $isbn : '',
			'cover'     => esc_url_raw( (string) $book['cover'], array( 'http', 'https' ) ),
			'year'      => preg_replace( '/\D/', '', (string) $book['year'] ),
			'publisher' => sanitize_text_field( (string) $book['publisher'] ),
		);
		if ( '' !== $entry['title'] ) { $clean[] = $entry; }
	}
	return array( 'books' => array_slice( $clean, 0, 6 ), 'source' => $source );
}

/**
 * Title, author, pages and cover for an ISBN: Open Library first, Google Books (with a key) for whatever is missing,
 * and the usual length of the work as a last resort for the pages. A WP_Error when nothing is found.
 */
function bcodex_isbn_lookup( $isbn ) {
	$isbn = bcodex_clean_isbn( $isbn );
	if ( ! in_array( strlen( $isbn ), array( 10, 13 ), true ) ) { return new WP_Error( 'fb_isbn', 'El ISBN debe tener 10 o 13 cifras.', array( 'status' => 400 ) ); }
	$open = bcodex_fetch_json( 'https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn . '&format=json&jscmd=data' )[ 'ISBN:' . $isbn ] ?? array();
	$found = array(
		'title'  => (string) ( $open['title'] ?? '' ),
		'author' => implode( ', ', array_filter( array_column( (array) ( $open['authors'] ?? array() ), 'name' ) ) ),
		'pages'  => (int) ( $open['number_of_pages'] ?? 0 ) ?: (int) preg_replace( '/^\D*(\d+).*$/s', '$1', (string) ( $open['pagination'] ?? '' ) ),
		'cover'  => (string) ( $open['cover']['large'] ?? '' ),
	);
	if ( in_array( '', $found, true ) || ! $found['pages'] ) {
		$google = bcodex_google( 'isbn:' . $isbn )['items'][0]['volumeInfo'] ?? array();
		$found['title']  = $found['title'] ?: (string) ( $google['title'] ?? '' );
		$found['author'] = $found['author'] ?: implode( ', ', array_filter( (array) ( $google['authors'] ?? array() ), 'is_string' ) );
		$found['pages']  = $found['pages'] ?: (int) ( $google['pageCount'] ?? 0 );
		$found['cover']  = $found['cover'] ?: set_url_scheme( (string) ( $google['imageLinks']['thumbnail'] ?? '' ), 'https' );
	}
	if ( ! $found['pages'] ) {
		$fields = 'https://openlibrary.org/search.json?limit=1&fields=number_of_pages_median';
		$work = bcodex_fetch_json( $fields . '&isbn=' . $isbn )['docs'][0] ?? array();
		if ( empty( $work['number_of_pages_median'] ) && '' !== $found['title'] ) { $work = bcodex_fetch_json( $fields . '&q=' . rawurlencode( $found['title'] ) )['docs'][0] ?? array(); }
		$found['pages'] = (int) ( $work['number_of_pages_median'] ?? 0 );
	}
	if ( '' === $found['title'] && ! $found['pages'] ) { return new WP_Error( 'fb_isbn_missing', 'No se ha encontrado ningún libro con ese ISBN. Puedes rellenar la ficha a mano.', array( 'status' => 404 ) ); }
	return array(
		'title'  => sanitize_text_field( $found['title'] ),
		'author' => sanitize_text_field( $found['author'] ),
		'pages'  => $found['pages'] ? (string) absint( $found['pages'] ) : '',
		'cover'  => esc_url_raw( $found['cover'], array( 'http', 'https' ) ),
	);
}
