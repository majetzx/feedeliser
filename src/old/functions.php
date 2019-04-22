<?php
// Feedeliser - Functions

/**
 * Get the number of URLs in the database
 *
 * @param SQLite3 SQLite database connection
 *
 * @return integer number of URLs
 */
function get_url_number($feeds_cache)
{
    $result = $feeds_cache->query('SELECT COUNT(*) AS nb FROM feed_entry');
    return $result->fetchArray(SQLITE3_ASSOC)['nb'];
}

/**
 * Transform a byte size into a human size
 *
 * @param integer $bytes bytes size
 * @param integer $decimals decimals number
 *
 * @return string the human file size
 */
function human_filesize($bytes, $decimals = 2)
{
    $sz = 'BKMGTP';
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}
