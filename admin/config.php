<?php
/**
 * Database settings.
 *
 * Fill these in from cPanel → MySQL Databases after you have created the
 * database and its user. Nothing else in the site needs editing.
 *
 * This file holds a password, so it must never be readable from a browser.
 * The .htaccess sitting next to it blocks the whole admin folder from direct
 * file access — leave that file alone.
 */

return [
    // From cPanel. GoDaddy prefixes both with your account name, e.g.
    // "abc123_flushstar" and "abc123_fsadmin" — copy them exactly as shown.
    'db_name' => 'REPLACE_WITH_DATABASE_NAME',
    'db_user' => 'REPLACE_WITH_DATABASE_USER',
    'db_pass' => 'REPLACE_WITH_DATABASE_PASSWORD',
    'db_host' => 'localhost',

    /**
     * Used to turn a visitor's IP into an anonymous daily id so we can count
     * unique visitors without ever storing the IP itself.
     *
     * Put any long random string here. Change it and yesterday's visitors stop
     * matching today's, so set it once and leave it.
     */
    'salt' => 'REPLACE_WITH_A_LONG_RANDOM_STRING',
];
