<?php
/**
 * Plugin Name: INPS Site Health watch
 * Description: Daily: runs Site Health direct tests and lists pending plugin/theme/core updates (major flagged); emails when the set changes. Respects inps-site-health-ignore.php. Delete this file to undo.
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
		// The REST test does a loopback request and is unreliable from cron/CLI; the CLI PHP has no opcache.
		if ( 'rest_availability' === $slug || ( 'opcode_cache' === $slug && 'cli' === PHP_SAPI ) ) {
			continue;
		}
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

	// Pending updates (plugins, themes, core). Major = first version segment changes.
	$updates = array();
	if ( function_exists( 'wp_update_plugins' ) ) {
		wp_update_plugins();
		wp_update_themes();
		wp_version_check();
	}
	$maj = function ( $v ) {
		return (int) preg_replace( '/[^0-9].*$/', '', (string) $v );
	};
	$p = get_site_transient( 'update_plugins' );
	if ( ! empty( $p->response ) ) {
		$all = get_plugins();
		foreach ( $p->response as $file => $u ) {
			$cur  = isset( $all[ $file ]['Version'] ) ? $all[ $file ]['Version'] : '?';
			$name = isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $file;
			$updates[ 'plugin:' . $file ] = array( ( $maj( $u->new_version ) > $maj( $cur ) ? 'MAJOR' : 'minor' ), "Plugin $name $cur -> {$u->new_version}" );
		}
	}
	$t = get_site_transient( 'update_themes' );
	if ( ! empty( $t->response ) ) {
		foreach ( $t->response as $slug => $u ) {
			$th  = wp_get_theme( $slug );
			$cur = $th->exists() ? $th->get( 'Version' ) : '?';
			$updates[ 'theme:' . $slug ] = array( ( $maj( $u['new_version'] ) > $maj( $cur ) ? 'MAJOR' : 'minor' ), "Theme $slug $cur -> {$u['new_version']}" );
		}
	}
	$c = get_site_transient( 'update_core' );
	if ( ! empty( $c->updates[0]->response ) && 'upgrade' === $c->updates[0]->response ) {
		$updates['core'] = array( ( $maj( $c->updates[0]->version ) > $maj( get_bloginfo( 'version' ) ) ? 'MAJOR' : 'minor' ), 'WordPress ' . get_bloginfo( 'version' ) . ' -> ' . $c->updates[0]->version );
	}
	// CiviCRM: installed vs latest stable (civicrm.org). Any newer release, even a patch, is listed.
	$civi_cur = '';
	$all_plugins = get_plugins();
	if ( ! empty( $all_plugins['civicrm/civicrm.php']['Version'] ) ) {
		$civi_cur = $all_plugins['civicrm/civicrm.php']['Version'];
	}
	if ( $civi_cur ) {
		$resp = wp_remote_get( 'https://latest.civicrm.org/stable.php', array( 'timeout' => 10 ) );
		$latest = is_wp_error( $resp ) ? '' : trim( wp_remote_retrieve_body( $resp ) );
		if ( preg_match( '/^\d+\.\d+\.\d+$/', $latest ) && version_compare( $latest, $civi_cur, '>' ) ) {
			$a = explode( '.', $civi_cur );
			$b = explode( '.', $latest );
			$kind = ( $a[0] !== $b[0] || $a[1] !== $b[1] ) ? 'MAJOR' : 'minor';
			$updates['civicrm'] = array( $kind, "CiviCRM $civi_cur -> $latest (install via our staging-first upgrade, not the WP Plugins page)" );
		}
	}
	ksort( $updates );

	$sig  = md5( wp_json_encode( array( $issues, $updates ) ) );
	$prev = get_option( 'inps_site_health_sig', '' );
	update_option( 'inps_site_health_sig', $sig, false );

	if ( ! $force && $sig === $prev ) {
		return;
	}
	if ( ! $force && empty( $issues ) && empty( $updates ) && '' === $prev ) {
		return;
	}

	$lines = array();
	foreach ( $issues as $slug => $i ) {
		$lines[] = strtoupper( $i[0] ) . ': ' . $i[1] . ' (' . $slug . ')';
	}
	$ulines = array();
	foreach ( $updates as $k => $i ) {
		$ulines[] = $i[0] . ': ' . $i[1];
	}
	$majors  = count( array_filter( $updates, function ( $i ) {
		return 'MAJOR' === $i[0];
	} ) );
	$subject = "[$host] " . ( $issues ? count( $issues ) . ' Site Health issue(s)' : 'Site Health clear' )
		. ( $updates ? ', ' . count( $updates ) . ' update(s)' . ( $majors ? " ($majors MAJOR)" : '' ) : ', no updates' );
	$body = ( $lines ? implode( "\n", $lines ) : 'No issues in the direct tests.' )
		. "\n\n" . ( $ulines ? "Pending updates:\n" . implode( "\n", $ulines ) : 'No pending updates.' )
		. ( $civi_cur ? "\n\nCiviCRM installed: $civi_cur" : '' )
		. "\n\nChecked: direct tests only (the REST, loopback and page-cache async tests are not run from cron)."
		. "\nSite Health: " . admin_url( 'site-health.php' )
		. "\nTo ignore an item, add its slug to inps-site-health-ignore.php.\n";
	wp_mail( INPS_SH_TO, $subject, $body );
}
