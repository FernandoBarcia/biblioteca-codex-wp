<?php
/**
 * The wallet of the app: what was bought with rupees in its bazaar and how the room of its Showcase is set up, kept here
 * so every device of an account shows the same. What was earned the app works out from the library itself, so only
 * what was spent travels. Each user has a wallet of their own, as each has a library of their own.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const BCODEX_WALLET_META = 'bcodex_wallet';

/** An id of the app: a piece of the bazaar or a slot of the room. */
function bcodex_wallet_id( $value ) {
	return is_string( $value ) && 1 === preg_match( '/^[a-z0-9-]{1,64}$/', $value );
}

/** The room as it travels: what is up in each slot (`ninguno` when taken down), what was taken down, the favourites and when it changed. */
function bcodex_wallet_room( $room ) {
	$room = is_array( $room ) ? $room : array();
	$slots = array();
	foreach ( (array) ( $room['slots'] ?? array() ) as $slot => $item ) {
		if ( bcodex_wallet_id( (string) $slot ) && bcodex_wallet_id( $item ) ) { $slots[ (string) $slot ] = $item; }
	}
	$list = fn( $v ) => array_slice( array_values( array_unique( array_filter( (array) $v, 'bcodex_wallet_id' ) ) ), 0, 500 );
	return array(
		'slots'     => (object) $slots,
		'hidden'    => $list( $room['hidden'] ?? array() ),
		'favorites' => $list( $room['favorites'] ?? array() ),
		'updated'   => is_string( $room['updated'] ?? null ) ? $room['updated'] : '',
	);
}

/** What is kept for a user, as stored. */
function bcodex_wallet_stored( $user ) {
	$wallet = get_user_meta( $user, BCODEX_WALLET_META, true );
	return is_array( $wallet ) ? $wallet : array();
}

/** A user's wallet, as the app reads it. */
function bcodex_wallet( $user ) {
	$wallet = bcodex_wallet_stored( $user );
	$purchases = is_array( $wallet['purchases'] ?? null ) ? array_values( $wallet['purchases'] ) : array();
	return array( 'purchases' => $purchases, 'room' => bcodex_wallet_room( $wallet['room'] ?? null ) );
}

/** Keeps a purchase. Sending it again (a retry, another device) changes nothing: each is kept once, by its id and by its piece. */
function bcodex_wallet_buy( $user, $id, $item, $amount, $reason, $date ) {
	$wallet = bcodex_wallet_stored( $user );
	$purchases = is_array( $wallet['purchases'] ?? null ) ? array_values( $wallet['purchases'] ) : array();
	foreach ( $purchases as $p ) {
		if ( $p['id'] === $id || $p['item'] === $item ) { return bcodex_wallet( $user ); }
	}
	$purchases[] = array( 'id' => $id, 'item' => $item, 'amount' => $amount, 'reason' => $reason, 'date' => $date );
	$wallet['purchases'] = $purchases;
	update_user_meta( $user, BCODEX_WALLET_META, $wallet );
	return bcodex_wallet( $user );
}

/** Keeps the room sent, unless the one kept changed later (on another device): the newest wins. */
function bcodex_wallet_set_room( $user, $room ) {
	$wallet = bcodex_wallet_stored( $user );
	$new = bcodex_wallet_room( $room );
	$kept = bcodex_wallet_room( $wallet['room'] ?? null );
	if ( '' === $kept['updated'] || strtotime( $new['updated'] ) >= strtotime( $kept['updated'] ) ) {
		$new['slots'] = (array) $new['slots'];
		$wallet['room'] = $new;
		update_user_meta( $user, BCODEX_WALLET_META, $wallet );
	}
	return bcodex_wallet( $user );
}
