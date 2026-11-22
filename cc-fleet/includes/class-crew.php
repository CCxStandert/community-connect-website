<?php
defined( 'ABSPATH' ) || exit;

/**
 * Crew accounts: "Send welcome email" on Users for Fleet Managers.
 *
 * The email (Community Connect design) says what a Fleet Manager can do and has a button to set
 * their own password (WordPress's normal reset link, valid for 24 hours). Nobody sees a password.
 * The row shows when the last welcome went out.
 */
class CC_Fleet_Crew {

	public static function init() {
		add_filter( 'user_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_post_cc_fleet_crew_invite', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function is_crew( WP_User $u ) {
		return $u->has_cap( 'manage_cc_fleet' ) && ! $u->has_cap( 'manage_options' );
	}

	public static function row_action( $actions, $user ) {
		if ( current_user_can( 'create_users' ) && self::is_crew( $user ) ) {
			$sent  = get_user_meta( $user->ID, 'cc_crew_invited', true );
			$label = $sent ? 'Send welcome email again (last ' . mysql2date( 'j M', $sent ) . ')' : 'Send welcome email';
			$actions['cc_invite'] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_crew_invite&user=' . $user->ID ), 'cc_fleet_crew_invite_' . $user->ID ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $actions;
	}

	/** @return bool|WP_Error */
	public static function invite( WP_User $user ) {
		if ( ! self::is_crew( $user ) ) {
			return new WP_Error( 'cc_crew', 'Only Fleet Manager accounts get the crew welcome.' );
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$set  = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$sent = CC_Fleet_Emails::crew_welcome( $user, $set, wp_login_url() );
		if ( ! $sent ) {
			return new WP_Error( 'cc_mail', 'The email didn’t go out. Check WP Mail SMTP.' );
		}
		update_user_meta( $user->ID, 'cc_crew_invited', current_time( 'mysql' ) );
		CC_Fleet_Install::log( 'user', $user->ID, 'crew welcome sent', $user->user_email );
		return true;
	}

	public static function handle() {
		$id = (int) ( $_GET['user'] ?? 0 );
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_crew_invite_' . $id );
		$user = get_userdata( $id );
		$res  = $user ? self::invite( $user ) : new WP_Error( 'cc_crew', 'User not found.' );
		set_transient( 'cc_fleet_crew_notice_' . get_current_user_id(), is_wp_error( $res ) ? array( 'error', $res->get_error_message() ) : array( 'success', 'Welcome email sent to ' . $user->display_name . ' (' . $user->user_email . '). Their set-password link works for 24 hours.' ), 60 );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'users.php' ) );
		exit;
	}

	public static function notice() {
		$n = get_transient( 'cc_fleet_crew_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'cc_fleet_crew_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[0] ), esc_html( $n[1] ) );
		}
	}
}
