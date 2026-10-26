<?php
/**
 * Plugin Name: INPS Site Health ignore list
 * Description: Removes specific Site Health tests we have decided to ignore. Add test slugs to the array. Delete this file to undo.
 */
add_filter( 'site_status_tests', function ( $tests ) {
	// Redis is not offered by Acorn (ticket #865162, Oct 24 2026).
	$ignore = array( 'persistent_object_cache' );
	foreach ( array( 'direct', 'async' ) as $group ) {
		foreach ( $ignore as $slug ) {
			unset( $tests[ $group ][ $slug ] );
		}
	}
	return $tests;
} );
