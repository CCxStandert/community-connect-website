<?php
/** Expenses. THROWAWAY site only: wp eval-file wp-content/plugins/cc-fleet/tests/expenses-test.php */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$GLOBALS['et_p'] = 0; $GLOBALS['et_f'] = 0;
function et( $n, $c, $x = '' ) { if ( $c ) { $GLOBALS['et_p']++; echo "  ✓ $n\n"; } else { $GLOBALS['et_f']++; echo "  ✗ $n" . ( $x ? " — $x" : '' ) . "\n"; } }
wp_set_current_user( 1 );
$bikes = CC_Fleet_Bikes::all_ids();
$a = CC_Fleet_Expenses::save( array( 'exp_date' => '2026-10-26', 'supplier' => 'Woodstock Cycleworks', 'invoice_no' => 'WCW0000428', 'description' => 'Three bikes', 'category' => 'repair', 'bikes' => array( $bikes[0], $bikes[1] ), 'amount' => 'R 3 360,00', 'vat' => '438.26', 'status' => 'unpaid', 'recover_amount' => '350', 'recover_from' => 'Luke' ) );
et( 'Expense saved', is_int( $a ) && $a > 0, is_wp_error( $a ) ? $a->get_error_message() : '' );
$e = CC_Fleet_Expenses::get( $a );
et( 'Amount parsed from "R 3 360,00"', 3360.0 === (float) $e->amount, $e->amount );
et( 'Bikes stored', 2 === count( CC_Fleet_Expenses::bike_ids( $e ) ) );
et( 'Shows in bike filter', 1 === count( array_filter( CC_Fleet_Expenses::query( array( 'bike' => $bikes[0] ) ), function ( $x ) use ( $a ) { return (int) $x->id === $a; } ) ) );
et( 'Shows in bike history', count( CC_Fleet_Expenses::history_items( $bikes[1] ) ) >= 1 );
CC_Fleet_Expenses::save( array( 'status' => 'paid' ), $a );
et( 'Paid gets a date', null !== CC_Fleet_Expenses::get( $a )->paid_on );
et( 'Invoice numbers match loosely', CC_Fleet_Expenses::norm_invoice( 'INVOICEwcw0000428_ Community connect_26 October 2026.pdf' ) === CC_Fleet_Expenses::norm_invoice( 'WCW0000428' ) && CC_Fleet_Expenses::norm_invoice( 'Invoice - INV1594-2.pdf' ) === CC_Fleet_Expenses::norm_invoice( 'INV1594' ) );
// Files
$pdf = wp_tempnam( 'x' ); file_put_contents( $pdf, "%PDF-1.4\n%test\n" );
$fake = wp_tempnam( 'y' ); file_put_contents( $fake, "<?php echo 1;" );
$r = CC_Fleet_Expenses::drop( array( array( $pdf, 'INVOICEwcw0000428_ Community connect_26 October 2026.pdf' ), array( $pdf, 'Invoice - INV9999.pdf' ), array( $fake, 'evil.pdf' ), array( $fake, 'evil.php' ) ) );
et( 'Dropped file matched by number', 1 === count( $r['matched'] ), wp_json_encode( $r ) );
et( 'Unknown invoice becomes a new expense', 1 === count( $r['created'] ) );
et( 'Fake PDF and PHP file are refused', 2 === count( $r['errors'] ), wp_json_encode( $r['errors'] ) );
$e = CC_Fleet_Expenses::get( $a );
et( 'File stored privately', $e->file && file_exists( CC_Fleet_Expenses::dir() . '/' . $e->file ) && file_exists( dirname( CC_Fleet_Expenses::dir() ) . '/.htaccess' ) );
et( 'File URL needs the admin handler', false !== strpos( CC_Fleet_Expenses::file_url( $e ), 'admin-post.php?action=cc_fleet_expense_file' ) );
$new = array_values( array_filter( CC_Fleet_Expenses::query(), function ( $x ) { return 'INV9999' === $x->invoice_no; } ) );
et( 'New expense keeps the number', 1 === count( $new ) && $new[0]->file );
// Empty save refused
et( 'Empty expense refused', is_wp_error( CC_Fleet_Expenses::save( array() ) ) );
// Page renders
$_GET = array( 'year' => 2026 ); ob_start(); CC_Fleet_Expenses::page(); $h = ob_get_clean();
et( 'List page shows totals', false !== strpos( $h, 'Spent in 2026' ) && false !== strpos( $h, 'R 3 360.00' ) );
$_GET = array( 'edit' => $a ); ob_start(); CC_Fleet_Expenses::page(); $h = ob_get_clean();
et( 'Edit page renders', false !== strpos( $h, 'WCW0000428' ) );
// Clean
global $wpdb;
foreach ( CC_Fleet_Expenses::query() as $x ) { if ( $x->file ) { wp_delete_file( CC_Fleet_Expenses::dir() . '/' . $x->file ); } }
$wpdb->query( 'DELETE FROM ' . CC_Fleet_Expenses::table() );
echo "\n{$GLOBALS['et_p']} passed, {$GLOBALS['et_f']} failed\n";
