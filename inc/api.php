<?php
/**
 * The API the app talks to, under /wp-json/fb-books/v1/.
 *
 * Access: an application password (Users → Profile → Application passwords) over HTTPS, sent as Basic authentication.
 * Reading needs «edit_posts»; changing a book needs the right to edit that book. An author or a contributor sees and
 * edits only their own books; an editor or an administrator, everyone's.
 *
 * GET    /books               the books, newest change first; ?modified_after= for sync
 * GET    /books/{id}          one book
 * POST   /books               a new book (title required); 409 if a book with that ISBN already exists
 * PATCH  /books/{id}          change only the fields sent
 * DELETE /books/{id}          move to the bin
 * GET    /isbn/{isbn}         title, author, pages and cover found for an ISBN, plus the book already kept with it, if any
 * GET    /search?title=&author=  up to six editions by title, to choose from; saves nothing
 * GET    /profile             who is connected and what the library adds up to
 * GET    /options             the lists the app's forms show
 * Covers: upload to /wp/v2/media and send its id as cover_id, or send an address as cover_url to be downloaded.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The fields the app reads and writes, as API name => field of the book. */
function bcodex_api_fields() {
	return array( 'author' => 'author', 'state' => 'status', 'format' => 'format', 'started' => 'started', 'finished' => 'finished', 'isbn' => 'isbn', 'pages' => 'pages', 'volumes' => 'volumes', 'volumes_read' => 'volumes_read', 'tome_layout' => 'tome_layout', 'series' => 'series', 'grouped' => 'grouped', 'position' => 'position', 'note' => 'note', 'rating' => 'rating', 'favorite' => 'favorite', 'marks' => 'marks', 'podium' => 'podium', 'legend' => 'legend', 'story' => 'story', 'cover_url' => 'cover' );
}

/** A book as the app sees it. */
function bcodex_api_book( $id ) {
	$id = (int) $id;
	$post = get_post( $id );
	$thumb = (int) get_post_thumbnail_id( $id );
	$cover = $thumb ? wp_get_attachment_image_url( $thumb, 'large' ) : (string) bcodex_meta( $id, 'cover' );
	$rating = bcodex_clean_rating( bcodex_meta( $id, 'rating' ) );
	$last = absint( bcodex_meta( $id, 'last_cover' ) );
	$layout = (string) bcodex_meta( $id, 'tome_layout' );
	$state = (string) bcodex_meta( $id, 'status' );
	return array(
		'id'            => $id,
		'title'         => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
		'status'        => $post->post_status,
		// The books have no public page here, so there is nothing to link to.
		'link'          => '',
		'modified'      => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $post->post_modified_gmt . ' UTC' ) ),
		'author'        => (string) bcodex_meta( $id, 'author' ),
		'state'         => array_key_exists( $state, bcodex_states() ) ? $state : 'unknown',
		'format'        => bcodex_format( $id ),
		'started'       => (string) bcodex_meta( $id, 'started' ),
		'finished'      => (string) bcodex_meta( $id, 'finished' ),
		'isbn'          => (string) bcodex_meta( $id, 'isbn' ),
		'pages'         => absint( bcodex_meta( $id, 'pages' ) ) ?: null,
		'volumes'       => absint( bcodex_meta( $id, 'volumes' ) ) ?: null,
		'volumes_read'  => absint( bcodex_meta( $id, 'volumes_read' ) ) ?: null,
		'last_cover'    => $last ? (string) wp_get_attachment_image_url( $last, 'large' ) : '',
		'last_cover_id' => $last ?: null,
		'tome_layout'   => in_array( $layout, bcodex_tome_layouts(), true ) ? $layout : '',
		'series'        => (string) bcodex_meta( $id, 'series' ),
		'grouped'       => '1' === (string) bcodex_meta( $id, 'grouped' ),
		'position'      => absint( bcodex_meta( $id, 'position' ) ) ?: null,
		'note'          => (string) bcodex_meta( $id, 'note' ),
		'rating'        => '' === $rating ? null : (float) $rating,
		'verdict'       => '' === $rating ? '' : bcodex_verdicts()[ $rating ][0],
		'masterpiece'   => bcodex_is_masterpiece( $id ),
		'favorite'      => '1' === (string) bcodex_meta( $id, 'favorite' ),
		'marks'         => bcodex_book_marks( $id ),
		// Seals belong to the full plugin; the list stays so the app reads the same shape from both.
		'seals'         => array(),
		'podium'        => absint( bcodex_meta( $id, 'podium' ) ) ?: null,
		'legend'        => absint( bcodex_meta( $id, 'legend' ) ) ?: null,
		'story'         => (string) bcodex_meta( $id, 'story' ),
		'content'       => (string) $post->post_content,
		'excerpt'       => (string) $post->post_excerpt,
		'cover'         => $cover ? (string) $cover : '',
		'cover_id'      => $thumb ?: null,
	);
}

/** The book the user sees with this ISBN (either length, any spacing), or 0. */
function bcodex_api_find_isbn( $isbn, $except = 0 ) {
	$isbn = bcodex_clean_isbn( $isbn );
	if ( '' === $isbn ) { return 0; }
	foreach ( bcodex_visible_ids() as $id ) {
		if ( (int) $id !== (int) $except && bcodex_clean_isbn( bcodex_meta( $id, 'isbn' ) ) === $isbn ) { return (int) $id; }
	}
	return 0;
}

/** Writes the fields present in the request; returns the book or a WP_Error. Leaves everything else as it was. */
function bcodex_api_write( $id, WP_REST_Request $request, $creating = false ) {
	$sent = $request->get_json_params() ?: $request->get_body_params();
	$sent = is_array( $sent ) ? $sent : array();
	$bad = fn( $code, $message, $status = 400 ) => new WP_Error( $code, $message, array( 'status' => $status ) );
	$post = array();
	if ( array_key_exists( 'title', $sent ) ) {
		$title = is_scalar( $sent['title'] ) ? trim( sanitize_text_field( (string) $sent['title'] ) ) : '';
		if ( '' === $title ) { return $bad( 'fb_title', 'El título no puede quedar vacío.' ); }
		$post['post_title'] = $title;
	}
	if ( array_key_exists( 'status', $sent ) ) {
		if ( ! in_array( $sent['status'], array( 'publish', 'draft', 'private' ), true ) ) { return $bad( 'fb_status', 'El estado de publicación debe ser publish, draft o private.' ); }
		if ( 'publish' === $sent['status'] && ! current_user_can( 'publish_posts' ) ) { return $bad( 'fb_publish', 'No tienes permiso para publicar.', 403 ); }
		$post['post_status'] = $sent['status'];
	}
	foreach ( array( 'content' => 'post_content', 'excerpt' => 'post_excerpt' ) as $name => $column ) {
		if ( ! array_key_exists( $name, $sent ) ) { continue; }
		if ( null !== $sent[ $name ] && ! is_string( $sent[ $name ] ) ) { return $bad( 'fb_field', 'Valor no válido en «' . $name . '».' ); }
		$post[ $column ] = (string) $sent[ $name ];
	}
	// Values are checked before anything is saved, so a rejected request changes nothing.
	$meta = array();
	foreach ( bcodex_api_fields() as $name => $key ) {
		if ( ! array_key_exists( $name, $sent ) ) { continue; }
		$value = $sent[ $name ];
		if ( in_array( $name, array( 'favorite', 'grouped' ), true ) ) { $value = $value ? '1' : '0'; }
		elseif ( 'marks' === $name ) { $value = is_array( $value ) ? array_map( 'strval', array_filter( $value, 'is_scalar' ) ) : array(); }
		elseif ( null === $value ) { $value = ''; }
		elseif ( is_bool( $value ) || ! is_scalar( $value ) ) { return $bad( 'fb_field', 'Valor no válido en «' . $name . '».' ); }
		$text = is_array( $value ) ? '' : (string) $value;
		if ( 'rating' === $name && '' !== $text && (float) bcodex_clean_rating( $text ) !== (float) str_replace( ',', '.', $text ) ) { return $bad( 'fb_rating', 'El veredicto debe ir de 0,5 a 5,5 en saltos de 0,5.' ); }
		if ( 'state' === $name && ! array_key_exists( $text, bcodex_states() ) ) { return $bad( 'fb_state', 'Estado de lectura no válido.' ); }
		if ( 'format' === $name && ! array_key_exists( $text, bcodex_formats() ) ) { return $bad( 'fb_format', 'Tipo no válido.' ); }
		if ( in_array( $name, array( 'started', 'finished' ), true ) && '' !== $text && '' === bcodex_clean_date( $text ) ) { return $bad( 'fb_date', 'La fecha de «' . $name . '» debe ser AAAA-MM-DD.' ); }
		if ( 'tome_layout' === $name && '' !== $text && ! in_array( $text, bcodex_tome_layouts(), true ) ) { return $bad( 'fb_layout', 'Diseño de la serie no válido.' ); }
		if ( 'podium' === $name && '' !== $text && ! in_array( $text, array( '1', '2', '3', '4', '5' ), true ) ) { return $bad( 'fb_podium', 'El puesto en el podio debe ir del 1 al 5.' ); }
		$meta[ $key ] = bcodex_clean_value( $key, $value );
	}
	// A record that stands for a whole series is not part of a saga: those fields are saved empty.
	if ( array_intersect_key( $meta, array_flip( array( 'volumes', 'series', 'grouped', 'position' ) ) ) && absint( $meta['volumes'] ?? ( $creating ? 0 : bcodex_meta( $id, 'volumes' ) ) ) >= 2 ) {
		$meta['series'] = ''; $meta['grouped'] = '0'; $meta['position'] = '';
	}
	$cover_id = null;
	foreach ( array( 'cover_id' => 'La portada', 'last_cover_id' => 'La portada del último tomo' ) as $name => $label ) {
		if ( ! array_key_exists( $name, $sent ) ) { continue; }
		$image = is_scalar( $sent[ $name ] ) ? absint( $sent[ $name ] ) : 0;
		if ( $image && ( ! wp_attachment_is_image( $image ) || ! current_user_can( 'edit_post', $image ) ) ) { return $bad( 'fb_cover', $label . ' debe ser una imagen de Medios que puedas editar.' ); }
		if ( 'cover_id' === $name ) { $cover_id = $image; } else { $meta['last_cover'] = $image ? (string) $image : ''; }
	}
	if ( $creating ) {
		$id = wp_insert_post( $post + array( 'post_type' => 'fb_book', 'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'draft' ), true );
		if ( is_wp_error( $id ) ) { return $id; }
		if ( ! isset( $meta['format'] ) ) { $meta['format'] = bcodex_guess_format( $post['post_title'], $meta['author'] ?? '' ); }
		if ( ! isset( $meta['status'] ) ) { $meta['status'] = 'unread'; }
	} elseif ( $post ) {
		$done = wp_update_post( $post + array( 'ID' => $id ), true );
		if ( is_wp_error( $done ) ) { return $done; }
	}
	bcodex_fill_dates( $id, $meta );
	foreach ( $meta as $key => $value ) { update_post_meta( $id, '_fb_book_' . $key, $value ); }
	if ( null !== $cover_id ) {
		if ( $cover_id ) {
			set_post_thumbnail( $id, $cover_id );
			if ( ! wp_get_post_parent_id( $cover_id ) ) { wp_update_post( array( 'ID' => $cover_id, 'post_parent' => $id ) ); }
		} else { delete_post_thumbnail( $id ); }
	}
	// A cover sent as an address is downloaded into Media.
	if ( bcodex_has_linked_cover( $id ) ) { bcodex_import_cover( $id ); }
	// Touch the record so ?modified_after= sees a change that only touched its details.
	if ( ! $post ) { wp_update_post( array( 'ID' => $id ) ); }
	clean_post_cache( $id );
	return bcodex_api_book( $id );
}

add_action( 'rest_api_init', function () {
	$ns = 'fb-books/v1';
	$can_read = fn() => current_user_can( 'edit_posts' );
	$can_edit = fn( WP_REST_Request $r ) => 'fb_book' === get_post_type( (int) $r['id'] ) && 'trash' !== get_post_status( (int) $r['id'] ) && current_user_can( 'edit_post', (int) $r['id'] );
	$params = fn( WP_REST_Request $r ) => (array) ( $r->get_json_params() ?: $r->get_body_params() );

	register_rest_route( $ns, '/books', array(
		array( 'methods' => 'GET', 'permission_callback' => $can_read, 'args' => array(
			'modified_after' => array( 'type' => 'string', 'format' => 'date-time' ),
			'page'           => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			'per_page'       => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 200 ),
		), 'callback' => function ( WP_REST_Request $r ) {
			$query = array( 'post_type' => 'fb_book', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids', 'posts_per_page' => (int) $r['per_page'], 'paged' => (int) $r['page'], 'no_found_rows' => false );
			// An author or a contributor gets only their own books.
			if ( ! current_user_can( 'edit_others_posts' ) ) { $query['author'] = get_current_user_id(); }
			if ( $r['modified_after'] ) { $query['date_query'] = array( array( 'column' => 'post_modified_gmt', 'after' => gmdate( 'Y-m-d H:i:s', strtotime( $r['modified_after'] ) ), 'inclusive' => false ) ); }
			$found = new WP_Query( $query );
			$response = rest_ensure_response( array_map( 'bcodex_api_book', $found->posts ) );
			$response->header( 'X-WP-Total', (string) $found->found_posts );
			$response->header( 'X-WP-TotalPages', (string) $found->max_num_pages );
			return $response;
		} ),
		array( 'methods' => 'POST', 'permission_callback' => $can_read, 'callback' => function ( WP_REST_Request $r ) use ( $params ) {
			$sent = $params( $r );
			if ( ! is_scalar( $sent['title'] ?? '' ) || '' === trim( (string) ( $sent['title'] ?? '' ) ) ) { return new WP_Error( 'fb_title', 'Falta el título.', array( 'status' => 400 ) ); }
			$same = is_scalar( $sent['isbn'] ?? '' ) ? bcodex_api_find_isbn( $sent['isbn'] ?? '' ) : 0;
			if ( $same ) { return new WP_Error( 'fb_duplicate', 'Ya tienes un libro con ese ISBN.', array( 'status' => 409, 'book' => bcodex_api_book( $same ) ) ); }
			$book = bcodex_api_write( 0, $r, true );
			if ( is_wp_error( $book ) ) { return $book; }
			$response = rest_ensure_response( $book );
			$response->set_status( 201 );
			return $response;
		} ),
	) );

	register_rest_route( $ns, '/books/(?P<id>\d+)', array(
		array( 'methods' => 'GET', 'permission_callback' => $can_edit, 'callback' => fn( WP_REST_Request $r ) => bcodex_api_book( (int) $r['id'] ) ),
		array( 'methods' => 'PATCH, PUT, POST', 'permission_callback' => $can_edit, 'callback' => function ( WP_REST_Request $r ) use ( $params ) {
			$sent = $params( $r );
			$same = isset( $sent['isbn'] ) && is_scalar( $sent['isbn'] ) ? bcodex_api_find_isbn( $sent['isbn'], (int) $r['id'] ) : 0;
			if ( $same ) { return new WP_Error( 'fb_duplicate', 'Ya tienes otro libro con ese ISBN.', array( 'status' => 409, 'book' => bcodex_api_book( $same ) ) ); }
			return bcodex_api_write( (int) $r['id'], $r );
		} ),
		array( 'methods' => 'DELETE', 'permission_callback' => fn( WP_REST_Request $r ) => 'fb_book' === get_post_type( (int) $r['id'] ) && current_user_can( 'delete_post', (int) $r['id'] ), 'callback' => function ( WP_REST_Request $r ) {
			$book = bcodex_api_book( (int) $r['id'] );
			return wp_trash_post( (int) $r['id'] ) ? array( 'trashed' => true, 'book' => $book ) : new WP_Error( 'fb_trash', 'No se ha podido mover a la papelera.', array( 'status' => 500 ) );
		} ),
	) );

	register_rest_route( $ns, '/isbn/(?P<isbn>[0-9Xx-]{10,17})', array( 'methods' => 'GET', 'permission_callback' => $can_read, 'callback' => function ( WP_REST_Request $r ) {
		$same = bcodex_api_find_isbn( $r['isbn'] );
		$found = bcodex_isbn_lookup( $r['isbn'] );
		if ( is_wp_error( $found ) && ! $same ) { return $found; }
		return array( 'found' => is_wp_error( $found ) ? null : $found, 'existing' => $same ? bcodex_api_book( $same ) : null );
	} ) );

	register_rest_route( $ns, '/search', array( 'methods' => 'GET', 'permission_callback' => $can_read, 'args' => array(
		'title'  => array( 'type' => 'string', 'required' => true ),
		'author' => array( 'type' => 'string', 'default' => '' ),
	), 'callback' => fn( WP_REST_Request $r ) => bcodex_title_search( $r['title'], $r['author'] ) ) );

	register_rest_route( $ns, '/profile', array( 'methods' => 'GET', 'permission_callback' => $can_read, 'callback' => function () {
		$user = wp_get_current_user();
		$year = wp_date( 'Y' );
		// The figures count the published books that stand in the library: not the drafts, not the wanted ones.
		$all = array_filter( bcodex_visible_ids(), fn( $id ) => 'publish' === get_post_status( $id ) );
		$books = array_values( array_filter( $all, fn( $id ) => 'wish' !== bcodex_meta( $id, 'status' ) ) );
		$read = array_values( array_filter( $books, fn( $id ) => 'read' === bcodex_meta( $id, 'status' ) ) );
		$in_year = fn( $list ) => array_filter( $list, fn( $id ) => $year === substr( (string) bcodex_meta( $id, 'finished' ), 0, 4 ) );
		$of = fn( $format, $list ) => array_filter( $list, fn( $id ) => $format === bcodex_format( $id ) );
		$goal = absint( bcodex_setting( 'goal', 0 ) );
		$scope = (string) bcodex_setting( 'goal_scope', 'all' );
		$done = count( array_filter( $in_year( 'all' === $scope ? $read : $of( $scope, $read ) ), 'bcodex_counts_for_goal' ) );
		return array(
			'name'              => (string) $user->display_name,
			'user'              => (string) $user->user_login,
			'avatar'            => (string) get_avatar_url( $user->ID, array( 'size' => 256 ) ),
			'site'              => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'library'           => '',
			'desiderata'        => '',
			'year'              => (int) $year,
			// Volumes count one by one.
			'books_read'        => count( $of( 'book', $read ) ),
			'manga_read'        => array_sum( array_map( 'bcodex_tomes', $of( 'manga', $books ) ) ),
			'read_year'         => array_sum( array_map( 'bcodex_tomes', $in_year( $read ) ) ),
			'unread'            => count( array_filter( $books, fn( $id ) => 'unread' === bcodex_meta( $id, 'status' ) ) ),
			'wishes'            => count( $all ) - count( $books ),
			'goal'              => $goal ? array( 'target' => $goal, 'done' => $done, 'scope' => $scope ) : null,
			'masterpieces'      => count( array_filter( $books, 'bcodex_is_masterpiece' ) ),
			// No pictures travel from here: the app shows its own.
			'masterpiece_image' => '',
			// Seals belong to the full plugin; the app hides them when there are none.
			'seals'             => array( 'total' => 0, 'special' => 0, 'gold' => 0, 'silver' => 0, 'bronze' => 0 ),
			'seal_images'       => array( 'gold' => '', 'silver' => '', 'bronze' => '' ),
		);
	} ) );

	register_rest_route( $ns, '/options', array( 'methods' => 'GET', 'permission_callback' => $can_read, 'callback' => function () {
		$verdicts = array();
		foreach ( bcodex_verdicts() as $value => $v ) { $verdicts[] = array( 'value' => (float) $value, 'label' => $v[0], 'phrase' => $v[1], 'image' => '' ); }
		$marks = array();
		foreach ( bcodex_marks() as $slug => $m ) { $marks[] = array( 'slug' => $slug, 'label' => $m[0], 'description' => $m[1] ); }
		$pairs = fn( $list ) => array_map( fn( $k, $v ) => array( 'value' => $k, 'label' => $v ), array_keys( $list ), $list );
		$sagas = bcodex_sagas();
		// form_version tells the app what the API can do: 2 adds the search by title; 3, /profile; 4, the state «wish» (the desiderata).
		// The designs of a series (tome_layouts) and the ornaments (version 5) belong to the full plugin and are left out.
		return array( 'form_version' => 4, 'plugin' => 'biblioteca-codex', 'states' => $pairs( bcodex_states() ), 'formats' => $pairs( bcodex_formats() ), 'verdicts' => $verdicts, 'marks' => $marks, 'series' => array_column( $sagas, 'name' ), 'sagas' => $sagas, 'authors' => bcodex_author_names(), 'library' => '' );
	} ) );
} );
