<?php
/**
 * Plugin Name: INPS Site Health watch
 * Description: Runs WordPress Site Health "direct" tests daily and emails when the set of issues changes. Respects inps-site-health-ignore.php. Delete this file to undo.
 */

define( 'INPS_SH_TO', 'ed@sharpertool.com' );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'inps_site_health_watch' ) ) {
		wp_schedule_event( time() + 600, 'daily', 'inps_site_health_watch' );
	}
} );

add_action( 'inps_site_health_watch', 'inps_site_health_watch' );

/**
 * @param bool $force Send a report even if nothing changed (for testing).
 */
function inps_site_health_watch( $force = false ) {
	foreach ( array( 'update.php', 'plugin.php', 'misc.php', 'file.php', 'class-wp-site-health.php' ) as $f ) {
		require_once ABSPATH . 'wp-admin/includes/' . $f;
	}
	$host       = wp_parse_url( home_url(), PHP_URL_HOST );
	$is_staging = ( false !== stripos( $host, 'staging' ) );
	$health     = WP_Site_Health::get_instance();
	$tests      = WP_Site_Health::get_tests();
	$issues     = array();

	foreach ( $tests['direct'] as $slug => $test ) {
		try {
			if ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
				$r = call_user_func( array( $health, 'get_test_' . $test['test'] ) );
			} elseif ( is_callable( $test['test'] ) ) {
				$r = call_user_func( $test['test'] );
			} else {
				continue;
			}
		} catch ( \Throwable $e ) {
			$issues[ $slug ] = array( 'critical', 'Test "' . $slug . '" threw: ' . $e->getMessage() );
			continue;
		}
		if ( empty( $r['status'] ) || 'good' === $r['status'] ) {
			continue;
		}
		$label = isset( $r['label'] ) ? $r['label'] : $slug;
		// Staging intentionally discourages search engines.
		if ( $is_staging && ( false !== stripos( $slug . $label, 'search_engine' ) || false !== stripos( $label, 'search engine' ) ) ) {
			continue;
		}
		$issues[ $slug ] = array( $r['status'], $label );
	}

	ksort( $issues );
	$sig  = md5( wp_json_encode( $issues ) );
	$prev = get_option( 'inps_site_health_sig', '' );
	update_option( 'inps_site_health_sig', $sig, false );

	if ( ! $force && $sig === $prev ) {
		return;
	}
	if ( ! $force && empty( $issues ) && '' === $prev ) {
		return;
	}

	$lines = array();
	foreach ( $issues as $slug => $i ) {
		$lines[] = strtoupper( $i[0] ) . ': ' . $i[1] . ' (' . $slug . ')';
	}
	$subject = empty( $issues )
		? "[$host] Site Health: all clear"
		: "[$host] Site Health: " . count( $issues ) . ' issue(s)';
	$body = ( $lines ? implode( "\n", $lines ) : 'No issues in the direct tests.' )
		. "\n\nChecked: direct tests only (the REST, loopback and page-cache async tests are not run from cron)."
		. "\nSite Health: " . admin_url( 'site-health.php' )
		. "\nTo ignore an item, add its slug to inps-site-health-ignore.php.\n";
	wp_mail( INPS_SH_TO, $subject, $body );
}
