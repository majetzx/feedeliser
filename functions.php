<?php
/**
 * Get the content from a URL
 *
 * @param string $feed_name the feed's name
 * @param string $url the URL
 * @param callable $after_first_url a callback to get a content on several pages
 * @param callable $custom_html_cleaner a callback to clean the web page content, called as is by web_parser()
 * @param string $original_title original page title
 *
 * @return array a array with keys status, title and content ; status can be "cache" ou "new"
 * @see web_parser()
 */
function get_url_content($feed_name, $url, $after_first_url = null, $custom_html_cleaner = null, $original_title = null)
{
	// The SQLite database containing web pages cache
    global $feeds_cache;
    
    $title = $content = $status = '';
    
	// Check if URL is already in cache
    $stmt = $feeds_cache->prepare('SELECT title, content FROM feed_entry WHERE feed=:feed AND url=:url');
    $stmt->bindValue(':feed', $feed_name, SQLITE3_TEXT);
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    
	// A result: read values and update the last access timestamp
    if ($row)
    {
        $status = 'cache';
        $title = $row['title'];
        $content = $row['content'];
        
        $stmt2 = $feeds_cache->prepare('UPDATE feed_entry SET last_access=:last_access WHERE url=:url');
        $stmt2->bindValue(':last_access', time(), SQLITE3_INTEGER);
        $stmt2->bindValue(':url', $url, SQLITE3_TEXT);
        $stmt2->execute();
        $stmt2->close();
    }
	// No result: get values and store them if successful
    else
    {
        $json = web_parser($url, $custom_html_cleaner, $original_title);
        log_data("retour web_parser() : title = $json->title");
        $status = 'new';
        $title = $json->title ?: '';
        $content = $json->content;
        
		// Get additional content
        if (is_callable($after_first_url))
        {
            $urls = call_user_func($after_first_url, $url);
            if (is_array($urls))
            {
                foreach ($urls as $supp_url)
                {
                    $json = web_parser($supp_url, $custom_html_cleaner);
                    if ($json !== false)
                    {
                        $content .= $json->content;
                    }
                }
            }
        }
        
        $content = html_entity_decode($content, ENT_NOQUOTES);
        
        $stmt2 = $feeds_cache->prepare('INSERT INTO feed_entry (feed, url, content, title, last_access) VALUES (:feed, :url, :content, :title, :last_access)');
        $stmt2->bindValue(':feed', $feed_name, SQLITE3_TEXT);
        $stmt2->bindValue(':url', $url, SQLITE3_TEXT);
        $stmt2->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt2->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt2->bindValue(':last_access', time(), SQLITE3_INTEGER);
        $stmt2->execute();
        $stmt2->close();
    }
    $result->finalize();
    $stmt->close();
    
    return array(
        'status' => $status,
        'title' => $title,
        'content' => $content,
    );
}

/**
 * Parse a web page and get the "useful" content
 *
 * @param string $url the web page URL
 * @param callable $custom_html_cleaner a callback to clean the web page content
 * @param string $original_title original page title
 *
 * @return object an object with keys title, content
 */
function web_parser($url, $custom_html_cleaner = null, $original_title = null)
{
	// Delete the query string from URL
    $url_parts = parse_url($url);
    $url = $url_parts['scheme'] . '://' . $url_parts['host'] . $url_parts['path'];
    
	// Get the content with Mercury web parser
    $ch = curl_init("https://mercury.postlight.com/parser?url=$url");
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl/' . curl_version()['version']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'x-api-key: K6Mc1SbkYgGkGV1YPjnSy8J2v19I2Qq0Sv7J0UMk',
    ));
    $json = curl_exec($ch);
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
	// If everything is ok, return the content
    if ($http_code == 200 && $json !== false)
    {
        $json = json_decode($json);
        if (is_object($json) && isset($json->content))
        {
            log_data("OK mercury");
            if ($original_title)
            {
                $json->title = $original_title;
            }
            return $json;
        }
    }
    
	// In case of error, try to get the page's full content
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.13; rv:64.0) Gecko/20100101 Firefox/64.0");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.5',
        'Cache-Control: no-cache',
        'DNT: 1',
    ));
    $full_html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $json = new StdClass;
    if ($http_code == 200 && $full_html !== false)
    {
		// A callback to clean the content
        if (is_callable($custom_html_cleaner))
        {
            list($content, $title) = call_user_func($custom_html_cleaner, $full_html, $original_title);
            $json->title = $title;
            $json->content = $content;
        }
        else
        {
            log_data("web_parser($url) : pas de callback custom_html_cleaner");
            $json->title = $original_title;
            $json->content = $full_html;
        }
    }
    else
    {
        log_data("web_parser($url) : erreur de récupération ($http_code)");
        $json->title = '⚠️ ' . $original_title;
        $json->content = "Impossible de récupérer le contenu (web_parser).";
    }
    
    return $json;
}

/**
 * Add namespaces to an XPath object
 *
 * @param DOMXPath $xpath XPath object
 * @param array $nss namespaces array, keys are namespaces, values are URLs
 */
function register_xpath_namespaces($xpath, $nss)
{
    foreach ($nss as $ns => $nsurl)
    {
        $xpath->registerNameSpace($ns, $nsurl);
    }
}

/**
 * Get a DOM node inner HTML
 *
 * @param DOMNode $element DOM node
 *
 * @return string HTML content
 */
function dom_inner_html($element)
{
    $inner_html = '';
    $children = $element->childNodes;
    foreach ($children as $child)
    {
        $tmp_dom = new DOMDocument();
        $tmp_dom->appendChild($tmp_dom->importNode($child, true));
        $inner_html .= trim($tmp_dom->saveHTML());
    }
    return $inner_html;
}

/**
 * Delete children nodes
 *
 * @param DOMNodeList $children children nodes to delete
 */
function dom_delete_children($children)
{
    for ($i = $children->length; --$i >= 0; )
    {
        $child = $children->item($i);
        $child->parentNode->removeChild($child);
    }
}

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

/**
 * Add a log line in the log file
 *
 * @param mixed $data datas to log
 */
function log_data($data)
{
    file_put_contents('datas/log', date('[c] ') . (string) $data . PHP_EOL, FILE_APPEND);
}