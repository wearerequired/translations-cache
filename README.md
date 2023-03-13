# Translations Cache

Simple WordPress must-use plugin to reduce file reads for gettext (.mo) and JavaScript (.json) translations by caching the first read via APCu.

By default the cache TTL is set to six hours without any automated cache invalidation. You can set the `TRANSLATIONS_CACHE_KEY_SALT` environment variable to change the key for the cache which will force the plugin to read from a fresh cache entry.

## Installation

1. Define the dropin path for `wordpress-muplugin`  
   ```
   composer config --json --merge extra.dropin-paths '{ "wordpress/content/mu-plugins/": [ "type:wordpress-muplugin" ] }'
   ```
1. Install `koodimonni/composer-dropin-installer` and `wearerequired/translations-cache`  
   ```
   composer require koodimonni/composer-dropin-installer wearerequired/translations-cache
   ```

Example of a `composer.json` for a site:

```json
{
  "name": "wearerequired/something",
  "require": {
    "koodimonni/composer-dropin-installer": "^1.0",
    "wearerequired/translations-cache": "^1.0"
  },
  "extra": {
    "dropin-paths": {
      "wordpress/content/mu-plugins/": [
        "type:wordpress-muplugin"
      ]
    }
  }
}
