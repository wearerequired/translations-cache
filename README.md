# Translations Cache

WordPress mu-plugin to reduces file reads for translations by caching the first read via APCu.

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
