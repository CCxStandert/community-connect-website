<?php
/**
 * Plugin Name:       CC Fleet
 * Description:       Community Connect fleet backend: bikes, riders, loans, availability calendar, request form, workshop log, stock, events calendar, notes, emails and calendar feeds.
 * Version:           0.13.3
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Community Connect NPC
 * Text Domain:       cc-fleet
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'CC_FLEET_VERSION', '0.13.3' );
define( 'CC_FLEET_DB_VERSION', '9' );
define( 'CC_FLEET_FILE', __FILE__ );
define( 'CC_FLEET_DIR', plugin_dir_path( __FILE__ ) );
define( 'CC_FLEET_URL', plugin_dir_url( __FILE__ ) );

require_once CC_FLEET_DIR . 'includes/class-settings.php';
require_once CC_FLEET_DIR . 'includes/class-install.php';
require_once CC_FLEET_DIR . 'includes/class-bikes.php';
require_once CC_FLEET_DIR . 'includes/class-phone.php';
require_once CC_FLEET_DIR . 'includes/class-riders.php';
require_once CC_FLEET_DIR . 'includes/class-loans.php';
require_once CC_FLEET_DIR . 'includes/class-emails.php';
require_once CC_FLEET_DIR . 'includes/class-outbox.php';
require_once CC_FLEET_DIR . 'includes/class-landing.php';
require_once CC_FLEET_DIR . 'includes/class-import.php';
require_once CC_FLEET_DIR . 'includes/class-records.php';
require_once CC_FLEET_DIR . 'includes/class-rest.php';
require_once CC_FLEET_DIR . 'includes/class-ical.php';
require_once CC_FLEET_DIR . 'includes/class-cron.php';
require_once CC_FLEET_DIR . 'includes/class-frontend.php';
require_once CC_FLEET_DIR . 'includes/class-privacy.php';
require_once CC_FLEET_DIR . 'includes/class-updater.php';
require_once CC_FLEET_DIR . 'includes/class-parts.php';
require_once CC_FLEET_DIR . 'includes/class-expenses.php';
require_once CC_FLEET_DIR . 'includes/class-feeds.php';
if ( is_admin() ) {
	require_once CC_FLEET_DIR . 'includes/class-admin.php';
	require_once CC_FLEET_DIR . 'includes/class-records-admin.php';
}

register_activation_hook( __FILE__, array( 'CC_Fleet_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CC_Fleet_Install', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	CC_Fleet_Install::maybe_upgrade();
	CC_Fleet_Bikes::init();
	CC_Fleet_Rest::init();
	CC_Fleet_Ical::init();
	CC_Fleet_Cron::init();
	CC_Fleet_Frontend::init();
	CC_Fleet_Landing::init();
	CC_Fleet_Records::init();
	CC_Fleet_Privacy::init();
	CC_Fleet_Updater::init();
	CC_Fleet_Parts::init();
	CC_Fleet_Care::init();
	CC_Fleet_Expenses::init();
	CC_Fleet_Feeds::init();
	if ( is_admin() ) {
		CC_Fleet_Admin::init();
		CC_Fleet_Records_Admin::init();
		CC_Fleet_Outbox::init();
	}
} );
