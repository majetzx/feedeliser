<?php
// Feedeliser - Main script

require_once 'config.php';
require_once 'functions.php';
require_once 'vendor/autoload.php';

ini_set('display_errors', 0);
define('FEEDELISER_VERSION', '0');

// Feed name in query string
$feed_name = filter_input(INPUT_SERVER, 'QUERY_STRING', FILTER_SANITIZE_STRING);

// No configuration file
if (!is_file(FEEDS_CONFS_DIR . "/$feed_name.php"))
{
    log_data("feed $feed_name: missing configuration file");
    exit;
}

$feed_config = require FEEDS_CONFS_DIR . "/$feed_name.php";
if (!isset($feed_config['url']) || !isset($feed_config['blocks']) || !isset($feed_config['block_callback']))
{
    log_data("feed $feed_name: incomplete configuration file");
    exit;
}

// Get the feed/page
$url_data = get_url($feed_config['url'], $feed_config['gzip'] ?? false);
$feed_body = $url_data['http_body'];
$http_code = $url_data['http_code'];

if ($http_code != 200)
{
    log_data("feed $feed_name: HTTP code = $http_code");
    exit;
}

// Cache SQlite database
$feeds_cache = new SQLite3(SQLITE_CACHE_DB_PATH, SQLITE3_OPEN_READWRITE);

// Analyze the HTML/XML using DOM and XPath, create returned XML
libxml_use_internal_errors(true);
$doc = new DOMDocument();

// An XML feed
if (isset($feed_config['xml']) && $feed_config['xml'])
{
    $doc->loadXML($feed_body);
    $xpath = new DOMXpath($doc);
    if (isset($feed_config['ns']) && is_array($feed_config['ns']))
    {
        register_xpath_namespaces($xpath, $feed_config['ns']);
    }
    $blocks = $xpath->query($feed_config['blocks']);
    if ($blocks)
    {
        // Call the callback on each block
        foreach ($blocks as $block)
        {
            $xpath2 = new DOMXpath($doc);
            if (isset($feed_config['ns']) && is_array($feed_config['ns']))
            {
                register_xpath_namespaces($xpath2, $feed_config['ns']);
            }
            $feed_config['block_callback']($doc, $block, $xpath2);
            unset($xpath2);
        }
    }
    unset($xpath);
    
    // RSS feed
    $output_data = $doc->saveXML();
}

// An HTML page
else
{
    $doc->loadHTML($feed_body);
    $xpath = new DOMXpath($doc);
    $blocks = $xpath->query($feed_config['blocks']);
    $blocks_infos = array();
    // Call the callback on each block
    if ($blocks)
    {
        foreach ($blocks as $block)
        {
            $blocks_infos[] = $feed_config['block_callback']($doc, $block);
        }
    }
    unset($xpath);
    
    // RSS feed
    $feed_title = $feed_config['title'] ?? $feed_name;
    $output_data = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>
<rss version=\"2.0\">
    <channel>
        <title>$feed_title</title>
        <link>{$feed_config['url']}</link>
        <description></description>";
    foreach ($blocks_infos as $block)
    {
        $block['title'] = htmlspecialchars($block['title'], ENT_XML1);
        $block['description'] = htmlspecialchars($block['description'], ENT_XML1);
        
        $output_data .= <<<EOT
        <item>
            <title>{$block['title']}</title>
            <link>{$block['url']}</link>
            <description>{$block['description']}</description>
            <pubDate>{$block['time']}</pubDate>
            <guid>{$block['url']}</guid>
        </item>
EOT;
    }
    
    $output_data .= "    </channel>
</rss>";
}

unset($doc);
$feeds_cache->close();

// Optional final callback
if (isset($feed_config['finalize']) && is_callable($feed_config['finalize']))
{
    $output_data = $feed_config['finalize']($output_data);
}

header('Content-Type: application/xml');
echo $output_data;
