<?php
// Feedeliser - Main script

require_once 'config.php';
require_once 'functions.php';

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
$ch = curl_init($feed_config['url']);
curl_setopt($ch, CURLOPT_USERAGENT, 'Feedeliser/' . FEEDELISER_VERSION . ' (+https://github.com/majetzx/feedeliser)');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
if (isset($feed_config['gzip']) && $feed_config['gzip'])
{
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
}
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.5',
    'Cache-Control: no-cache',
));
$data = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code != 200)
{
    log_data("feed $feed_name: HTTP code = $http_code");
    exit;
}

// Cache SQlite database
$feeds_cache = new SQLite3(SQLITE_CACHE_DB_PATH, SQLITE3_OPEN_READWRITE);

// Analyze the HTML/XML using DOM and XPath, create returned XML
header('Content-Type: application/xml');
libxml_use_internal_errors(true);
$doc = new DOMDocument();

// An XML feed
if (isset($feed_config['xml']) && $feed_config['xml'])
{
    $doc->loadXML($data);
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
    $data = $doc->saveXML();
}

// An HTML page
else
{
    $doc->loadHTML($data);
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
    $data = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>
<rss version=\"2.0\">
    <channel>
        <title>$feed_title</title>
        <link>{$feed_config['url']}</link>
        <description></description>";
    foreach ($blocks_infos as $block)
    {
        $block['title'] = htmlspecialchars($block['title'], ENT_XML1);
        $block['description'] = htmlspecialchars($block['description'], ENT_XML1);
        
        $data .= <<<EOT
        <item>
            <title>{$block['title']}</title>
            <link>{$block['url']}</link>
            <description>{$block['description']}</description>
            <pubDate>{$block['time']}</pubDate>
            <guid>{$block['url']}</guid>
        </item>
EOT;
    }
    
    $data .= "    </channel>
</rss>";
}

unset($doc);
$feeds_cache->close();

// Optional final callback
if (isset($feed_config['finalize']) && is_callable($feed_config['finalize']))
{
    $data = $feed_config['finalize']($data);
}

echo $data;
