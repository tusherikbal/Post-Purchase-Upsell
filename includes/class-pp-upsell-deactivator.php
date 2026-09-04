<?php
/**
 * Deactivation handler.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Deactivator
 */
class PP_Upsell_Deactivator {

	/**
	 * Runs on plugin deactivation. Deliberately non-destructive -- offers,
	 * order bumps, and attempt history all persist across deactivate/
	 * reactivate. Permanent deletion only happens on uninstall, and only if
	 * the store owner opted into it via Settings.
	 */
	public static function deactivate() {
		// Nothing to clean up on deactivate.
	}
}
