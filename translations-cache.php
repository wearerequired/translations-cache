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

declare( strict_types=1 );

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
	$cache_key      = 'load_script_translations:' . md5( $cache_key_salt . $locale . $file . $handle . $domain );

	$found        = false;
	$translations = cache_fetch( $cache_key, $found );
	if ( ! $found ) {
		// Call the core function to load the translations without the pre-filter.
		remove_filter( 'pre_load_script_translations', __NAMESPACE__ . '\load_script_translations', 9999 );
		$translations = \load_script_translations( $file, $handle, $domain );
		add_filter( 'pre_load_script_translations', __NAMESPACE__ . '\load_script_translations', 9999, 4 );

		// Cache the result.
		cache_add( $cache_key, $translations, false === $translations ? HOUR_IN_SECONDS : DEFAULT_EXPIRE );
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
 * @param string $locale   Optional. Locale. Default is the current locale.
 * @return bool True if the .mo file was loaded, false otherwise.
 */
function load_textdomain( bool $override, string $domain, string $mofile, ?string $locale = null ): bool {
	// Another plugin has already overridden the loading.
	if ( false !== $override ) {
		return $override;
	}

	/** @var \WP_Textdomain_Registry $wp_textdomain_registry */
	global $l10n, $wp_textdomain_registry;

	if ( ! $locale ) {
		$locale = determine_locale();
	}

	$cache_key_salt = getenv( 'TRANSLATIONS_CACHE_KEY_SALT' ) ?: '';
	$cache_key      = 'load_textdomain:' . md5( $cache_key_salt . $locale . $domain . $mofile );

	$found = false;
	$data  = cache_fetch( $cache_key, $found );
	if ( ! $found ) {
		// Allow plugins to change the .mo file.
		$mofile = apply_filters( 'load_textdomain_mofile', $mofile, $domain ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Core filter.

		if ( ! is_readable( $mofile ) ) {
			cache_add( $cache_key, false, DEFAULT_EXPIRE );

			return true;
		}

		$mo = new \MO();
		if ( ! $mo->import_from_file( $mofile ) ) {
			$wp_textdomain_registry->set( $domain, $locale, false );

			// Use a short cache time to avoid repeated failed lookups.
			cache_add( $cache_key, false, HOUR_IN_SECONDS );

			// Return true since we still override the .mo file loading.
			return true;
		}

		$wp_textdomain_registry->set( $domain, $locale, dirname( $mofile ) );

		// Cache the translations.
		$data = [
			'entries' => $mo->entries,
			'headers' => $mo->headers,
		];
		cache_add( $cache_key, $data, DEFAULT_EXPIRE );

		if ( isset( $l10n[ $domain ] ) ) {
			$mo->merge_with( $l10n[ $domain ] );
		}

		$l10n[ $domain ] = &$mo; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return true;
	} elseif ( \is_array( $data ) ) { // false if a mo file was not read/found.
		$mo          = new \MO();
		$mo->entries = $data['entries'];
		$mo->headers = $data['headers'];

		if ( isset( $l10n[ $domain ] ) ) {
			$mo->merge_with( $l10n[ $domain ] );
		}

		$l10n[ $domain ] = &$mo; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return true;
	}

	// Return true since we still override the .mo file loading.
	return true;
}
add_filter( 'pre_load_textdomain', __NAMESPACE__ . '\load_textdomain', 9999, 4 );

/**
 * Caches data to APCu, only if it's not already stored.
 *
 * @param string $key    The cache key to use for retrieval later.
 * @param mixed  $data   The contents to store in the cache.
 * @param int    $expire Optional. When to expire the cache contents, in seconds.
 *                       Default 0 (no expiration).
 * @return bool|array<string,mixed> Returns true if something has effectively been added into the cache,
 *                    false otherwise. Second syntax returns array with error keys.
 */
function cache_add( string $key, $data, int $expire = 0 ) {
	// Alter provided expire values to be within -10%/+20% of the provided time,
	// to spread expires over a wider window.
	$expire = rand( \intval( $expire * 0.9 ), \intval( $expire * 1.2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_rand

	return apcu_add( $key, $data, $expire );
}

/**
 * Retrieves the cache contents from APCu by key.
 *
 * @param string    $key   The key under which the cache contents are stored.
 * @param bool|null $found Optional. Whether the key was found in the cache (passed by reference).
 *                         Disambiguates a return of false, a storable value. Default null.
 * @return mixed The stored variable or array of variables on success; false on failure.
 */
function cache_fetch( string $key, ?bool &$found = null ) {
	return apcu_fetch( $key, $found );
}
