<?php
/**
 * Checks the API from inside WordPress, with no web server: `wp eval-file tests/api-test.php` on a throwaway site
 * with the users `autora` and `otro` (authors), `colabora` (contributor) and `admin`. It creates and bins books.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Start from an empty library and no settings.
foreach ( get_posts( array( 'post_type' => 'fb_book', 'post_status' => 'any,trash', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) as $old ) { wp_delete_post( $old, true ); }
foreach ( get_posts( array( 'post_type' => 'fb_book', 'post_status' => 'trash', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) as $old ) { wp_delete_post( $old, true ); }
delete_option( 'bcodex_settings' );

$failed = 0; $passed = 0;
$check = function ( $what, $ok ) use ( &$failed, &$passed ) { if ( $ok ) { $passed++; } else { $failed++; echo "FALLA: $what\n"; } };
$as = function ( $login ) { wp_set_current_user( $login ? get_user_by( 'login', $login )->ID : 0 ); };
$call = function ( $method, $path, $body = null, $query = array() ) {
	$request = new WP_REST_Request( $method, '/fb-books/v1' . $path );
	$request->set_query_params( $query );
	if ( null !== $body ) { $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $body ) ); }
	$response = rest_do_request( $request );
	return array( $response->get_status(), rest_get_server()->response_to_data( $response, false ), $response->get_headers() );
};

// The lookups answer from here, not from the network.
$asked = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$asked ) {
	$asked[] = $url;
	$body = '{}';
	if ( str_contains( $url, 'openlibrary.org/api/books' ) ) { $body = '{"ISBN:9788497592208":{"title":"Momo","authors":[{"name":"Michael Ende"}],"number_of_pages":288,"cover":{"large":"https://covers.openlibrary.org/b/id/1-L.jpg"}}}'; }
	if ( str_contains( $url, 'openlibrary.org/search.json' ) ) { $body = '{"docs":[{"title":"Momo","author_name":["Michael Ende"],"number_of_pages_median":288,"isbn":["9788497592208"],"cover_i":7,"first_publish_year":1973,"publisher":["Alfaguara"]}]}'; }
	if ( str_contains( $url, 'googleapis.com' ) ) { $body = '{"items":[{"volumeInfo":{"title":"Momo (Google)","authors":["Michael Ende"],"pageCount":320,"industryIdentifiers":[{"type":"ISBN_13","identifier":"9788497592208"}]}}]}'; }
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $body, 'headers' => array(), 'cookies' => array() );
}, 10, 3 );

// Nobody gets in without signing in.
$as( '' );
foreach ( array( '/books', '/options', '/profile', '/isbn/9788497592208' ) as $path ) { $check( "sin sesión, $path se niega", 401 === $call( 'GET', $path )[0] ); }
$check( 'sin sesión no se crea', 401 === $call( 'POST', '/books', array( 'title' => 'X' ) )[0] );

// The lists the app's forms show.
$as( 'autora' );
list( $status, $options ) = $call( 'GET', '/options' );
$check( 'opciones', 200 === $status && 4 === $options['form_version'] && 6 === count( $options['states'] ) && 11 === count( $options['verdicts'] ) && 13 === count( $options['marks'] ) && ! isset( $options['tome_layouts'] ) );
$check( 'los veredictos van sin imagen: la app pone las suyas', array( '' ) === array_values( array_unique( array_column( $options['verdicts'], 'image' ) ) ) && 'Obra maestra' === $options['verdicts'][0]['label'] && 5.5 === $options['verdicts'][0]['value'] );

// A new book, with the defaults of the app.
list( $status, $book ) = $call( 'POST', '/books', array( 'title' => ' Momo ', 'author' => 'Michael Ende', 'isbn' => '978-84-9759-220-8', 'pages' => 288 ) );
$id = $book['id'] ?? 0;
$check( 'alta', 201 === $status && 'Momo' === $book['title'] && 'unread' === $book['state'] && 'book' === $book['format'] && '9788497592208' === $book['isbn'] && 288 === $book['pages'] && 'publish' === $book['status'] && '' === $book['link'] && array() === $book['seals'] );
$check( 'alta sin título', 400 === $call( 'POST', '/books', array( 'title' => '  ' ) )[0] );
list( $status, $error ) = $call( 'POST', '/books', array( 'title' => 'Otro Momo', 'isbn' => '9788497592208' ) );
$check( 'ISBN repetido', 409 === $status && $id === ( $error['data']['book']['id'] ?? 0 ) );
$manga = $call( 'POST', '/books', array( 'title' => 'Berserk', 'author' => 'Kentaro Miura', 'volumes' => 41, 'volumes_read' => 3, 'state' => 'reading', 'series' => 'Berserk', 'grouped' => true ) )[1];
$check( 'un manga se reconoce por su autor, y una serie en una ficha no es de una saga', 'manga' === $manga['format'] && 41 === $manga['volumes'] && '' === $manga['series'] && false === $manga['grouped'] && wp_date( 'Y-m-d' ) === $manga['started'] );

// Changes: only what is sent, by the rules of the editor.
list( $status, $read ) = $call( 'PATCH', "/books/$id", array( 'state' => 'read', 'rating' => 4.5, 'marks' => array( 'funny', 'nope', 'moving' ), 'favorite' => true, 'note' => '<b>Corto</b> y bueno' ) );
$check( 'leído con veredicto', 200 === $status && 'read' === $read['state'] && wp_date( 'Y-m-d' ) === $read['finished'] && 4.5 === $read['rating'] && 'Sobresaliente' === $read['verdict'] && array( 'moving', 'funny' ) === $read['marks'] && true === $read['favorite'] && 'Corto y bueno' === $read['note'] && 'Michael Ende' === $read['author'] );
$check( 'obra maestra', true === $call( 'PATCH', "/books/$id", array( 'rating' => 5.5 ) )[1]['masterpiece'] );
foreach ( array( array( 'rating' => 4.3 ), array( 'state' => 'nada' ), array( 'format' => 'cómic' ), array( 'started' => '2026-02-30' ), array( 'podium' => 9 ), array( 'tome_layout' => 'raro' ), array( 'title' => '' ), array( 'status' => 'trash' ), array( 'pages' => array( 1 ) ), array( 'cover_id' => 999999 ) ) as $bad ) {
	$check( 'valor rechazado: ' . wp_json_encode( $bad ), 400 === $call( 'PATCH', "/books/$id", $bad )[0] );
}
$check( 'un rechazo no cambia nada', 5.5 === $call( 'GET', "/books/$id" )[1]['rating'] );
$check( 'una portada de la red interna no se descarga', '' === $call( 'PATCH', "/books/$id", array( 'cover_url' => 'http://127.0.0.1/portada.jpg' ) )[1]['cover'] || null === $call( 'GET', "/books/$id" )[1]['cover_id'] );
$check( 'un guion en las notas se limpia', ! str_contains( $call( 'PATCH', "/books/$id", array( 'content' => 'Hola<script>alert(1)</script>' ) )[1]['content'], '<script' ) );

// The list, its pages and the sync by date.
list( $status, $list, $headers ) = $call( 'GET', '/books' );
$check( 'lista', 200 === $status && 2 === count( $list ) && '2' === (string) $headers['X-WP-Total'] );
$check( 'nada cambió después de mañana', 0 === count( $call( 'GET', '/books', null, array( 'modified_after' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ) ) )[1] ) );

// The lookups: Open Library alone without a key, Google too with one.
list( $status, $found ) = $call( 'GET', '/isbn/9788497592208' );
$check( 'ISBN: lo encontrado y el que ya tienes', 200 === $status && 'Momo' === $found['found']['title'] && '288' === $found['found']['pages'] && $id === $found['existing']['id'] );
$search = $call( 'GET', '/search', null, array( 'title' => 'Momo' ) )[1];
$check( 'título sin clave: Open Library, y Google ni se pregunta', 'Open Library' === $search['source'] && 'Momo' === $search['books'][0]['title'] && ! array_filter( $asked, fn( $url ) => str_contains( $url, 'googleapis' ) ) );
update_option( 'bcodex_settings', array( 'google_key' => 'clave de prueba' ) );
$search = $call( 'GET', '/search', null, array( 'title' => 'Momo' ) )[1];
$check( 'título con clave: Google, con la clave', 'Google Books' === $search['source'] && 'Momo (Google)' === $search['books'][0]['title'] && (bool) array_filter( $asked, fn( $url ) => str_contains( $url, 'googleapis' ) && str_contains( $url, 'key=clave%20de%20prueba' ) ) );
$check( 'búsqueda sin título', 400 === $call( 'GET', '/search', null, array( 'title' => 'M' ) )[0] );

// The figures.
update_option( 'bcodex_settings', array( 'goal' => 12, 'goal_scope' => 'all' ) );
$call( 'POST', '/books', array( 'title' => 'One Piece', 'author' => 'Eiichiro Oda', 'state' => 'wish' ) );
$profile = $call( 'GET', '/profile' )[1];
$check( 'perfil', 'autora' === $profile['user'] && 1 === $profile['books_read'] && 3 === $profile['manga_read'] && 1 === $profile['read_year'] && 1 === $profile['wishes'] && 0 === $profile['unread'] && 1 === $profile['masterpieces'] && array( 'target' => 12, 'done' => 1, 'scope' => 'all' ) === $profile['goal'] && 0 === $profile['seals']['total'] && '' === $profile['masterpiece_image'] );

// Another author neither sees nor touches these books.
$as( 'otro' );
$check( 'otro autor no ve los libros', 0 === count( $call( 'GET', '/books' )[1] ) );
$check( 'ni los abre', 403 === $call( 'GET', "/books/$id" )[0] );
$check( 'ni los cambia', 403 === $call( 'PATCH', "/books/$id", array( 'title' => 'Mío' ) )[0] );
$check( 'ni los borra', 403 === $call( 'DELETE', "/books/$id" )[0] );
$check( 'ni se entera de que el ISBN existe', null === $call( 'GET', '/isbn/9788497592208' )[1]['existing'] );
$check( 'y puede tener el mismo ISBN en su biblioteca', 201 === $call( 'POST', '/books', array( 'title' => 'Momo', 'isbn' => '9788497592208' ) )[0] );
$check( 'sus cifras son las suyas', 0 === $call( 'GET', '/profile' )[1]['books_read'] );

// A contributor writes drafts and cannot publish.
$as( 'colabora' );
list( $status, $draft ) = $call( 'POST', '/books', array( 'title' => 'Borrador' ) );
$check( 'un colaborador crea borradores', 201 === $status && 'draft' === $draft['status'] );
$check( 'y no publica', 403 === $call( 'PATCH', '/books/' . $draft['id'], array( 'status' => 'publish' ) )[0] );

// An administrator sees every library.
$as( 'admin' );
$check( 'un administrador lo ve todo', 5 === count( $call( 'GET', '/books' )[1] ) );

// The bin.
$as( 'autora' );
list( $status, $gone ) = $call( 'DELETE', "/books/$id" );
$check( 'a la papelera', 200 === $status && true === $gone['trashed'] && 'trash' === get_post_status( $id ) );
$check( 'y ya no se abre', in_array( $call( 'GET', "/books/$id" )[0], array( 403, 404 ), true ) );

// The sample books go to one user, once; the one whose ISBN that user already has is left out.
$user = get_user_by( 'login', 'otro' )->ID;
$check( 'libros de ejemplo', 12 === bcodex_add_sample_books( $user ) && 0 === bcodex_add_sample_books( $user ) );
$as( 'otro' );
$samples = $call( 'GET', '/books' )[1];
$check( 'con su portada', 13 === count( $samples ) && 12 === count( array_filter( $samples, fn( $b ) => str_starts_with( $b['cover'], 'https://covers.openlibrary.org/b/isbn/' ) ) ) );

// The books have no public page.
$check( 'los libros no son públicos', ! is_post_type_viewable( 'fb_book' ) && ! get_post_type_object( 'fb_book' )->show_in_rest );

echo $failed ? "\n$failed fallos, $passed correctas\n" : "Todo correcto: $passed comprobaciones\n";
