<?php
defined( 'ABSPATH' ) || exit;

/**
 * POPIA / GDPR: hooks rider data into WordPress's Tools → Export / Erase Personal Data.
 */
class CC_Fleet_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', function ( $e ) {
			$e['cc-fleet'] = array( 'exporter_friendly_name' => 'Community Connect bike loans', 'callback' => array( __CLASS__, 'export' ) );
			return $e;
		} );
		add_filter( 'wp_privacy_personal_data_erasers', function ( $e ) {
			$e['cc-fleet'] = array( 'eraser_friendly_name' => 'Community Connect bike loans', 'callback' => array( __CLASS__, 'erase' ) );
			return $e;
		} );
		add_action( 'admin_init', function () {
			if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
				wp_add_privacy_policy_content( 'CC Fleet', '<p>When you request a bike we store your name, email, phone number, Instagram handle, height and the dates and plan of your ride, to arrange the loan and the handoff between riders. Only the Community Connect crew can see these details. Ask us any time to see or delete your data.</p>' );
			}
		} );
	}

	public static function export( $email, $page = 1 ) {
		$r = CC_Fleet_Riders::find_by_email( $email );
		if ( ! $r ) {
			return array( 'data' => array(), 'done' => true );
		}
		$items   = array();
		$items[] = array( 'group_id' => 'cc-rider', 'group_label' => 'Rider profile', 'item_id' => 'cc-rider-' . $r->id, 'data' => array(
			array( 'name' => 'Name', 'value' => $r->name ), array( 'name' => 'Email', 'value' => $r->email ),
			array( 'name' => 'Phone', 'value' => $r->phone ), array( 'name' => 'Instagram', 'value' => $r->instagram ),
			array( 'name' => 'Height (cm)', 'value' => (string) $r->height_cm ),
			array( 'name' => 'Weight (kg)', 'value' => (string) ( $r->weight_kg ?? '' ) ),
		) );
		foreach ( CC_Fleet_Loans::query( array( 'rider_id' => $r->id, 'limit' => 1000 ) ) as $l ) {
			$items[] = array( 'group_id' => 'cc-loans', 'group_label' => 'Bike loans', 'item_id' => 'cc-loan-' . $l->id, 'data' => array(
				array( 'name' => 'Bike', 'value' => $l->bike_id ? CC_Fleet_Bikes::public_label( $l->bike_id ) : '' ),
				array( 'name' => 'Dates', 'value' => $l->start_date . ' – ' . $l->end_date ),
				array( 'name' => 'Status', 'value' => $l->status ), array( 'name' => 'Plan', 'value' => (string) $l->plan ),
			) );
		}
		return array( 'data' => $items, 'done' => true );
	}

	/** Anonymises the rider; loans stay (without personal data) so fleet history and stats remain correct. */
	public static function erase( $email, $page = 1 ) {
		$r = CC_Fleet_Riders::find_by_email( $email );
		if ( ! $r ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		global $wpdb;
		$wpdb->update( CC_Fleet_Riders::table(), array(
			'name' => 'Deleted rider', 'email' => '', 'phone' => '', 'instagram' => '', 'height_cm' => null, 'weight_kg' => null, 'notes' => '', 'updated_at' => current_time( 'mysql' ),
		), array( 'id' => $r->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . CC_Fleet_Loans::table() . " SET plan = '', notes = '' WHERE rider_id = %d", $r->id ) );
		return array( 'items_removed' => true, 'items_retained' => false, 'messages' => array( 'Rider anonymised; loan dates kept for fleet history.' ), 'done' => true );
	}
}
