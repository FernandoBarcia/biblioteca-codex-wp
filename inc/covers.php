<?php
/**
 * Covers kept on the site. A cover sent as an address is downloaded into Media and becomes the book's featured image;
 * a JPG or PNG uploaded for a book is saved as WebP. The uploaded cover always wins over an address.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// A cover shown from another site: an address and no uploaded image.
function bcodex_has_linked_cover( $id ) {
	return ! has_post_thumbnail( $id ) && (bool) wp_http_validate_url( (string) bcodex_meta( $id, 'cover' ) );
}

// Set while a cover is being downloaded for a book, so the WebP conversion below also applies to it.
function bcodex_sideloading( $set = null ) {
	static $on = false;
	if ( null !== $set ) { $on = (bool) $set; }
	return $on;
}

/** Downloads the linked cover into Media and makes it the featured image. Returns the attachment ID, or a WP_Error. */
function bcodex_import_cover( $id ) {
	if ( has_post_thumbnail( $id ) ) { return (int) get_post_thumbnail_id( $id ); }
	$url = (string) bcodex_meta( $id, 'cover' );
	// wp_http_validate_url() and download_url() refuse addresses inside the server's own network.
	if ( ! wp_http_validate_url( $url ) ) { return new WP_Error( 'fb_cover_url', 'La dirección de la portada no es válida.' ); }
	// The same address downloaded before for this book is reused instead of duplicated in Media.
	$known = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $id, 'meta_key' => '_fb_cover_source', 'meta_value' => $url, 'fields' => 'ids', 'numberposts' => 1, 'suppress_filters' => true ) );
	if ( $known ) { set_post_thumbnail( $id, $known[0] ); return (int) $known[0]; }
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = download_url( $url, 15 );
	if ( is_wp_error( $tmp ) ) { return $tmp; }
	// Addresses from Google Books and similar carry no extension; the file is named after the book with the type it really has.
	$type = wp_get_image_mime( $tmp );
	$ext = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' )[ $type ] ?? '';
	if ( ! $ext ) { wp_delete_file( $tmp ); return new WP_Error( 'fb_cover_type', 'La dirección no lleva a una imagen.' ); }
	$file = array( 'name' => sanitize_file_name( ( sanitize_title( get_the_title( $id ) ) ?: 'portada-' . $id ) . '.' . $ext ), 'tmp_name' => $tmp );
	bcodex_sideloading( true );
	$thumb = media_handle_sideload( $file, $id, get_the_title( $id ) );
	bcodex_sideloading( false );
	if ( is_wp_error( $thumb ) ) { wp_delete_file( $tmp ); return $thumb; }
	update_post_meta( $thumb, '_fb_cover_source', $url );
	set_post_thumbnail( $id, $thumb );
	return (int) $thumb;
}

function bcodex_upload_is_for_book() {
	foreach ( array( 'post_id', 'post' ) as $key ) {
		$id = isset( $_REQUEST[ $key ] ) && is_scalar( $_REQUEST[ $key ] ) ? absint( $_REQUEST[ $key ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- the upload handler has already verified the request.
		if ( $id && 'fb_book' === get_post_type( $id ) ) { return true; }
	}
	return false;
}

add_filter( 'wp_handle_upload', function ( $upload, $context ) {
	$for_book = 'upload' === $context ? bcodex_upload_is_for_book() : ( 'sideload' === $context && bcodex_sideloading() );
	if ( ! $for_book || ! empty( $upload['error'] ) || ! in_array( $upload['type'] ?? '', array( 'image/jpeg', 'image/png' ), true ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) { return $upload; }
	$editor = wp_get_image_editor( $upload['file'] );
	if ( is_wp_error( $editor ) ) { return $upload; }
	// WebP keeps no EXIF orientation, so photos taken with a phone are rotated first.
	if ( method_exists( $editor, 'maybe_exif_rotate' ) ) { $editor->maybe_exif_rotate(); }
	$editor->set_quality( 82 );
	$dir = dirname( $upload['file'] );
	$name = wp_unique_filename( $dir, pathinfo( $upload['file'], PATHINFO_FILENAME ) . '.webp' );
	$saved = $editor->save( $dir . '/' . $name, 'image/webp' );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) { return $upload; }
	wp_delete_file( $upload['file'] );
	$upload['url'] = substr( $upload['url'], 0, strrpos( $upload['url'], '/' ) + 1 ) . basename( $saved['path'] );
	$upload['file'] = $saved['path'];
	$upload['type'] = 'image/webp';
	return $upload;
}, 10, 2 );
