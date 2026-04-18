<?php
/**
 * Uninstall hook for Ar Design Reporting.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// V první verzi nemažeme data automaticky.
// Auditní a reporting data mají zůstat zachována, dokud nebude doplněná retenční politika.
