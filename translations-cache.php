<?php // phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
/**
 * Plugin Name: Translations Cache
 * Description: Reduces file reads for translations by caching the first read via APCu.
 * Version:     1.1.0
 * Author:      required
 * Author URI:  https://required.com/
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:  false
 */

namespace Required\TranslationsCache;

// Default cache expiration time in seconds.
// Note: There's currently no cache invalidation but you can set the
// `TRANSLATIONS_CACHE_KEY_SALT` environment variable to invalidate the cache.
const DEFAULT_EXPIRE = 6 * HOUR_IN_SECONDS;

/**
 * Adds the APCu PHP module to the list of required modules.
 *
 * @param array<string,mixed> $modules An associative array of modules to test for.
 * @return array<string,mixed> An associative array of modules to test for.
 */
function site_status_test_apcu_php_module( array $modules ): array {
	$modules['apcu'] = [
		'extension' => 'apcu',
		'function'  => 'apcu_enabled',
		'required'  => true,
	];

	return $modules;
}
add_filter( 'site_status_test_php_modules', __NAMESPACE__ . '\site_status_test_apcu_php_module' );

// Bail early if APCu is not available.
if ( ! function_exists( 'apcu_enabled' ) || ! apcu_enabled() ) {
	return;
}

/**
 * Caches reading JSON translation files.
 *
 * @param string|false|null $translations JSON-encoded translation data. Default null.
 * @param string|false      $file         Path to the translation file to load. False if there isn't one.
 * @param string            $handle       Name of the script to register a translation domain to.
 * @param string            $domain       The text domain.
 * @return string|false The JSON-encoded translated strings for the given script handle and text domain.
 *                      False if there are none.
 */
function load_script_translations( $translations, $file, string $handle, string $domain ) { // phpcs:ignore SlevomatCodingStandard.TypeHints
	// Another plugin has already overridden the translations.
	if ( null !== $translations ) {
		return $translations;
	}

	$locale = determine_locale();

	$cache_key_salt = getenv( 'TRANSLATIONS_CACHE_KEY_SALT' ) ?: '';
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection
	$cache_key = 'load_script_translations:' . md5( $cache_key_salt . $locale . serialize( \func_get_args() ) );

	$found        = false;
	$translations = apcu_fetch( $cache_key, $found );
	if ( ! $found ) {
		// Call the core function to load the translations without the pre-filter.
		remove_filter( 'pre_load_script_translations', __NAMESPACE__ . '\load_script_translations', 9999 );
		$translations = \load_script_translations( $file, $handle, $domain );
		add_filter( 'pre_load_script_translations', __NAMESPACE__ . '\load_script_translations', 9999, 4 );

		// Cache the result.
		if ( false === $translations ) {
			$expiration = HOUR_IN_SECONDS;
		} else {
			$expiration = DEFAULT_EXPIRE;
		}
		apcu_add( $cache_key, $translations, $expiration );
	}

	return $translations;
}
add_filter( 'pre_load_script_translations', __NAMESPACE__ . '\load_script_translations', 9999, 4 );

/**
 * Caches reading gettext translation files.
 *
 * @param bool   $override Whether to override the .mo file loading. Default false.
 * @param string $domain   Text domain. Unique identifier for retrieving translated strings.
 * @param string $mofile   Path to the MO file.
 * @return bool True if the .mo file was loaded, false otherwise.
 */
function load_textdomain( bool $override, string $domain, string $mofile ): bool {
	// Another plugin has already overridden the loading.
	if ( false !== $override ) {
		return $override;
	}

	/** @var \WP_Textdomain_Registry $wp_textdomain_registry */
	global $l10n, $wp_textdomain_registry;

	$locale = determine_locale();

	$cache_key_salt = getenv( 'TRANSLATIONS_CACHE_KEY_SALT' ) ?: '';
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection
	$cache_key = 'load_textdomain:' . md5( $cache_key_salt . $locale . serialize( \func_get_args() ) );

	$found = false;
	$data  = apcu_fetch( $cache_key, $found );
	if ( ! $found ) {
		// Allow plugins to change the .mo file.
		$mofile = apply_filters( 'load_textdomain_mofile', $mofile, $domain ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Core filter.

		if ( ! is_readable( $mofile ) ) {
			apcu_add( $cache_key, false, HOUR_IN_SECONDS );

			// Return true since we still override the .mo file loading.
			return true;
		}

		$mo = new \MO();
		if ( ! $mo->import_from_file( $mofile ) ) {
			$wp_textdomain_registry->set( $domain, $locale, false );

			apcu_add( $cache_key, false, HOUR_IN_SECONDS );

			// Return true since we still override the .mo file loading.
			return true;
		}

		$wp_textdomain_registry->set( $domain, $locale, dirname( $mofile ) );

		// Cache the translations.
		$data = [
			'entries' => $mo->entries,
			'headers' => $mo->headers,
		];
		apcu_add( $cache_key, $data, DEFAULT_EXPIRE );

		if ( isset( $l10n[ $domain ] ) ) {
			$mo->merge_with( $l10n[ $domain ] );
		}

		$l10n[ $domain ] = &$mo; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	} elseif ( \is_array( $data ) ) { // false if a mo file was not read/found.
		$mo          = new \MO();
		$mo->entries = $data['entries'];
		$mo->headers = $data['headers'];

		if ( isset( $l10n[ $domain ] ) ) {
			$mo->merge_with( $l10n[ $domain ] );
		}

		$l10n[ $domain ] = &$mo; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	// Return true since we still override the .mo file loading.
	return true;
}
add_filter( 'override_load_textdomain', __NAMESPACE__ . '\load_textdomain', 9999, 4 );
