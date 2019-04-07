<?php
require_once 'functions.php';
ini_set('display_errors', 0);

$size_before = filesize('datas/feeds.sqlite');
$feeds_cache = new SQLite3('datas/feeds.sqlite', SQLITE3_OPEN_READWRITE);
$urls_before = get_url_number($feeds_cache);

// The feeds
foreach(scandir('.') as $path)
{
    // Only feeds configuration files
    if (!is_file($path) || $path == 'index.php' || $path == 'clean_cache.php' || $path == 'functions.php' || pathinfo($path, PATHINFO_EXTENSION) != 'php')
    {
        continue;
    }
    
    $feed_config = require $path;
    $feed_name = pathinfo($path, PATHINFO_FILENAME);
    echo "Feed $feed_name: ";
    
	// If the feed does not have a cache limit (uncached feed)
    if (!isset($feed_config['feed_cache_limit']))
    {
        echo 'no cache limit' . PHP_EOL;
        continue;
    }
    
	// Calculate the date before which entries must be deleted
    $last_access = time() - $feed_config['feed_cache_limit'];
    echo 'delete before ' . date('Y-m-d H:i:s', $last_access);
    $stmt = $feeds_cache->prepare('DELETE FROM feed_entry WHERE feed=:feed AND last_access<:datetime');
    $stmt->bindValue(':feed', $feed_name, SQLITE3_TEXT);
    $stmt->bindValue(':datetime', $last_access, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    echo ', ' . $feeds_cache->changes() . ' rows deleted';
    
    unset($feed_config);
    echo PHP_EOL;
}

$urls_after = get_url_number($feeds_cache);
// Clean up empty space
$feeds_cache->exec('VACUUM');
$feeds_cache->close();
$size_after = filesize('datas/feeds.sqlite');

// Summary
$size_diff = $size_before - $size_after;
echo "Size: " . human_filesize($size_before) . " → " . human_filesize($size_after) . " (-" . human_filesize($size_diff) . ")\n";
$urls_diff = $urls_before - $urls_after;
echo "URLs: $urls_before → $urls_after (-$urls_diff)\n";
