# About wp-pet-finder
WordPress plugin that makes a local copy of the pet finder database,
using the PetFinder API, for your local website.

# How to use
Place the plugin into your WordPress theme's plugin directory.
In your adminstrative panel, select the PetFinder Plugin option and install it onto your theme.
A new panel will be added to your administrative sidebar for PetFinder.


An **API KEY**, **API SECRET**, and **SHELTER ID** are **REQUIRED** for the plugin to work.
To get these, you must register on the Pet Finder’s [developers portal](https://www.petfinder.com/developers/api-docs).

# WordPress Shortcodes
This plugin generates two new available shortcodes you can use in your page content editor.

```
'petfinder-display-pets (animal=<DOG|CAT>) (count=<any number>)'
```

  Display all the pets onto the page. **_animal_ and _count_ are optional parameters.**

```
'petfinder-update-pets'
```

  Force the plugin to update the locale database

# Shortcode Example

```
petfinder-dipsplay-pets animal=DOG count=400
```

# Plugin API

```php
/**
 * checks configuration and returns if plugin is configured
 *
 * @return bool
 */
public function isConfigured();

/**
 * Singleton pattern. Creates a static instance of itself if none exist.
 * Returns a reference to itself.
 *
 * @return null|PetfinderLocalDB
 */
static function instance();

/**
 * Returns the table name
 *
 * @return string
 */
public function getTableName()

/**
 * Returns the version number
 *
 * @return string
 */
public function getVersion()

/**
 * Returns the shelter ID
 *
 * @return mixed json object notation
 */
public function getShelterID()

/**
 * Returns dogs from the database
 *
 * @param int $count default: 10 dogs
 * @return mixed json object notation
 */
public function getDogs()

/**
 * Returns cats from the database
 *
 * @param int $count default: 10 cats
 * @return mixed json object notation
 */
public function getCats()

/*
 * Update plugin options in WP and create a table in the database if necessary
 */

public function installPlugin()

/*
 * Delete plugin options in WP ad drop a table database
 */
public function uninstallPlugin()
```
