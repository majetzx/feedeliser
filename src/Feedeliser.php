<?php
declare(strict_types=1);

namespace majetzx\feedeliser;

use Psr\Log\LoggerInterface;
use DOMDocument, DOMNode, DOMNodeList, DOMXPath, SQLite3;
use andreskrey\Readability\{Readability, Configuration, ParseException};

/**
 * Main class
 */
class Feedeliser
{
    /**
     * Feedeliser version
     * @var string
     */
    const FEEDELISER_VERSION = '1';

    /**
     * XML UTF-8 prologue
     * @var string
     */
    const XML_PROLOGUE = '<?xml version="1.0" encoding="utf-8"?>';

    /**
     * Feeds configurations directory
     * @var string
     */
    public static $feeds_confs_dir = 'feeds';
    
    /**
     * SQLite database for caching contents
     * @var string
     */
    public static $sqlite_cache_db_path = 'datas/feeds.sqlite';

    /**
     * Text file used by cURL to store cookies
     * @var string
     */
    public static $curl_cookie_jar = 'datas/curl_cookies';

    /**
     * Text file containing one IP address per line, one is randomly chosen for outbound connection
     * @var string
     */
    public static $curl_ip_addresses_file = 'datas/curl_ips';

    /**
     * Feeds public directory
     * @var string
     */
    public static $public_dir = 'public';

    /**
     * Logger
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * SQLite feeds cache
     * @var \SQLite3
     */
    protected static $feeds_cache;

    /**
     * Whether to use an IP address from those in static::$curl_ip_addresses, if available
     * @var bool
     */
    protected static $curl_use_ip_address = false;

    /**
     * List of IP addresses for curl outbound connection, if available
     * @var string[]
     */
    protected static $curl_ip_addresses = [];

    /**
     * Constructor
     * 
     * @param \Psr\Log\LoggerInterface $logger PSR-3 compliant logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        // Feed name in query string
        $feed_name = filter_input(INPUT_SERVER, 'QUERY_STRING', FILTER_SANITIZE_STRING);

        $this->logger->debug("Feedeliser::__construct(), feed \"$feed_name\": start");

        // No configuration file
        if (!is_file(static::$feeds_confs_dir . "/$feed_name.php"))
        {
            $this->logger->error("Feedeliser::__construct(), feed \"$feed_name\": missing configuration file");
            return false;
        }

        $this->initializeCurlIpAddresses();

        // Create an anonymous object and generate the feed
        (new Feed(
            $this,
            $feed_name,
            require static::$feeds_confs_dir . "/$feed_name.php",
            $logger
        ))->generate();
    }

    /**
     * Open the SQLite cache connection
     */
    protected function openCache()
    {
        if (!static::$feeds_cache)
        {
            static::$feeds_cache = new SQLite3(static::$sqlite_cache_db_path, SQLITE3_OPEN_READWRITE);
        }
    }

    /**
     * Initialize outbound IP addresses to be used by curl, from a text file
     * 
     * @see static::$curl_ip_addresses
     */
    protected function initializeCurlIpAddresses()
    {
        static::$curl_use_ip_address = false;
        if (static::$curl_ip_addresses_file && is_file(static::$curl_ip_addresses_file))
        {
            $ips = [];
            foreach (file(static::$curl_ip_addresses_file) as $ip)
            {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
                {
                    $ips[] = $ip;
                }
            }
            if (count($ips))
            {
                static::$curl_ip_addresses = $ips;
                static::$curl_use_ip_address = true;
            }
        }
    }

    /**
     * Get content from a URL
     * 
     * @param string $url URL
     * 
     * @return array an array with the keys "http_body" and "http_code"
     */
    public function getUrlContent(string $url): array
    {
        $ch = curl_init($url);
        // Feedeliser identifies itself as a real browser to bypass bot protections
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.97 Safari/537.36');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // empty string to accept all encodings
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
            'Accept-Language: en-GB,en-US;q=0.9,en;q=0.8',
            'Cache-Control: max-age=0',
            'Upgrade-Insecure-Requests: 1',
            'Connection: keep-alive',
        ));
        // Storing cookies set by websites decreases the probability of being blocked
        curl_setopt($ch, CURLOPT_COOKIEFILE, static::$curl_cookie_jar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, static::$curl_cookie_jar);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // Outbound IP address
        if (static::$curl_use_ip_address)
        {
            $key = array_rand(static::$curl_ip_addresses);
            curl_setopt($ch, CURLOPT_INTERFACE, static::$curl_ip_addresses[$key]);
            $this->logger->debug("Feedeliser::getUrlContent($url): use <" . static::$curl_ip_addresses[$key] . "> outbound IP address");
        }
        $http_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->logger->debug("Feedeliser::getUrlContent($url): $http_code");

        return [
            'http_body' => $http_body,
            'http_code' => $http_code,
        ];    
    }

    /**
     * Get an item content, from cache if available or from the URL
     * 
     * The status can be:
     *  - "cache" if the content is in cache
     *  - "new" if the content is not in cache and is get from the URL
     *  - "error" if the content is not in cache and can't get it from the URL
     * 
     * @param \majetzx\feedeliser\Feed $feed the Feed object
     * @param string $url the item URL
     * @param string $original_title the original item title
     * @param string $original_content the original item content
     * 
     * @return array an array with keys "status", "title", "content", and for podcast feeds keys "enclosure_url", "image_url", "duration"
     */
    public function getItemContent(Feed $feed, string $url, string $original_title, string $original_content): array
    {
        $this->logger->debug("Feedeliser::getItemContent($feed, $url): start");
        
        $this->openCache();
        $status = $title = $content = '';
        $cache_available = true;

        // Check if URL is already in cache
        $get_stmt = static::$feeds_cache->prepare(
            'SELECT title, content FROM feed_entry WHERE feed = :feed AND url = :url'
        );

        // Error using the cache
        if (!$get_stmt)
        {
            $this->logger->warning("Feedeliser::getItemContent($feed, $url): can't prepare cache statement");
            $cache_available = false;
        }

        if ($cache_available)
        {
            $get_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
            $get_stmt->bindValue(':url', $url, SQLITE3_TEXT);
            $result = $get_stmt->execute();
            $row = $result->fetchArray();

            // A result: read values and update the last access timestamp
            if ($row)
            {
                $this->logger->debug("Feedeliser::getItemContent($feed, $url): found in cache");

                $status = 'cache';
                $title = $row['title'];
                $content = $row['content'];
                
                $update_stmt = static::$feeds_cache->prepare(
                    'UPDATE feed_entry SET last_access = :last_access WHERE feed = :feed AND url = :url'
                );
                $update_stmt->bindValue(':last_access', time(), SQLITE3_INTEGER);
                $update_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                $update_stmt->bindValue(':url', $url, SQLITE3_TEXT);
                $update_stmt->execute();
                $update_stmt->close();
            }

            $result->finalize();
            $get_stmt->close();
        }

        // If not found in cache, get URL content
        if ($status != 'cache')
        {
            $this->logger->debug("Feedeliser::getItemContent($feed, $url): not found in cache");

            $url_content = $this->getUrlContent($url);
            if ($url_content['http_code'] == 200)
            {
                $status = 'new';

                // Change encoding if it's not in the target encoding
                $encoding = mb_detect_encoding($url_content['http_body']);
                $this->logger->debug("Feedeliser::getItemContent($feed, $url): detected encoding \"$encoding\"");
                if ($encoding !== false && $encoding != Feed::TARGET_ENCODING)
                {
                    $this->logger->debug("Feedeliser::getItemContent($feed, $url): convert from encoding \"$encoding\"");
                    $url_content['http_body'] = iconv($encoding, Feed::TARGET_ENCODING, $url_content['http_body']);
                }

                // Clean the content with Readability
                if ($feed->getReadability())
                {
                    $readability = new Readability(new Configuration());
                    try
                    {
                        $readability->parse($url_content['http_body']);
                        $title = $readability->getTitle();
                        $content = $readability->getContent();
                    }
                    catch (ParseException $e)
                    {
                        $this->logger->warning(
                            "Feedeliser::getItemContent($feed, $url): Readability exception while parsing content from URL",
                            [
                                'exception' => $e,
                            ]
                        );
                        $status = 'error';
                    }
                }

                // Reuse original values if new ones are empty
                if (!$title)
                {
                    $title = $original_title;
                }
                if (!$content)
                {
                    $content = $original_content;
                }

                // A callback on the content
                $item_callback = $feed->getItemCallback();
                if ($item_callback)
                {
                    call_user_func_array($item_callback, [&$title, &$content, $url_content['http_body']]);
                }

                // Reuse original values if new ones are empty
                if (!$title)
                {
                    $title = $original_title;
                }
                if (!$content)
                {
                    $content = $original_content;
                }

                // Do some standard cleaning on the title and content
                list($title, $content) = str_replace(
                    ['&#xD;', '&#xA;', "\xC2\x92", ],
                    [' ',     ' ',     '’',        ],
                    [$title, $content]
                );
                list($title, $content) = preg_replace(
                    '/\s\s+/',
                    ' ',
                    [$title, $content]
                );

                // If title and content are empty, it's a problem
                if (!$title && !$content)
                {
                    $this->logger->warning(
                        "Feedeliser::getItemContent($feed, $url): empty title and content for URL"
                    );
                    $status = 'error';
                }
                
                // Store clean content in cache if available
                if ($status == 'new' && $cache_available)
                {
                    $set_stmt = static::$feeds_cache->prepare(
                        'INSERT INTO feed_entry (feed, url, content, title, last_access) ' .
                        'VALUES (:feed, :url, :content, :title, :last_access)'
                    );
                    $set_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                    $set_stmt->bindValue(':url', $url, SQLITE3_TEXT);
                    $set_stmt->bindValue(':content', $content, SQLITE3_TEXT);
                    $set_stmt->bindValue(':title', $title, SQLITE3_TEXT);
                    $set_stmt->bindValue(':last_access', time(), SQLITE3_INTEGER);
                    $set_stmt->execute();
                    $set_stmt->close();
                }
            }
            else
            {
                $this->logger->warning(
                    "Feedeliser::getItemContent($feed, $url): can't get content from URL, invalid HTTP code {$url_content['http_code']}"
                );
                $status = 'error';
                if ($url_content['http_code'] == 403)
                {
                    $title = '⛔️ ' . $original_title;
                    $this->logger->error($url_content['http_body']);
                }
                else
                {
                    $title = '⚠️ ' . $original_title;
                }
                $content = $original_content;
            }
        }

        $item_content = [
            'status' => $status,
            'title' => $title,
            'content' => $content,
        ];

        // Feed is a podcast, get additional values
        if ($feed->getPodcast())
        {
            $podcast_status = $podcast_enclosure = '';
            $podcast_duration = 0;

            $this->logger->debug("Feedeliser::getItemContent($feed, $url): [podcast] start");

            $podcast_get_stmt = static::$feeds_cache->prepare(
                'SELECT enclosure, duration FROM podcast_entry WHERE feed = :feed AND url = :url'
            );

            // Error using the cache
            if (!$podcast_get_stmt)
            {
                $this->logger->warning("Feedeliser::getItemContent($feed, $url): [podcast] can't prepare cache statement");
                $cache_available = false;
            }
            
            if ($cache_available)
            {
                $podcast_get_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                $podcast_get_stmt->bindValue(':url', $url, SQLITE3_TEXT);
                $podcast_result = $podcast_get_stmt->execute();
                $podcast_row = $podcast_result->fetchArray();
    
                if ($podcast_row)
                {
                    $this->logger->debug("Feedeliser::getItemContent($feed, $url): [podcast] found in cache");
    
                    $podcast_status = 'cache';
                    $podcast_enclosure = $podcast_row['enclosure'];
                    $podcast_duration = $podcast_row['duration'];
                }
    
                $podcast_result->finalize();
                $podcast_get_stmt->close();
            }

            // If not found in cache, get URL content
            if ($podcast_status != 'cache')
            {
                $this->logger->debug("Feedeliser::getItemContent($feed, $url): [podcast] not found in cache");

                //TODO
            }

            $item_content['enclosure_url'] = $podcast_enclosure;
            $item_content['duration'] = $podcast_duration;
        }

        return $item_content;
    }

    /**
     * Return a podcast image URL, from cache if available or from a feed/item content
     * 
     * @param \majetzx\feedeliser\Feed $feed the Feed object
     * @param string $type image type, "feed" or "entry"
     * @param string $id empty for "feed" type, item URL for "entry" type
     * @param \DOMXpath $xpath
     * @param ?\DOMNode $item
     * 
     * @return string
     */
    public function getPodcastImage(Feed $feed, string $type, string $id, \DOMXpath $xpath, ?\DOMNode $item = null): string
    {
        $file = '';

        // Check in cache first
        $this->openCache();

        $get_stmt = static::$feeds_cache->prepare(
            'SELECT file FROM image WHERE feed = :feed AND type = :type AND id = :id'
        );

        // Error using the cache
        if (!$get_stmt)
        {
            $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): can't prepare cache statement");
            return '';
        }

        $get_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
        $get_stmt->bindValue(':type', $type, SQLITE3_TEXT);
        $get_stmt->bindValue(':id', $id, SQLITE3_TEXT);
        $result = $get_stmt->execute();
        $row = $result->fetchArray();
        $result->finalize();
        $get_stmt->close();

        if ($row)
        {
            $this->logger->debug("Feedeliser::getPodcastImage($feed, $type, $id): found in cache");
            // Found in cache but missing file
            if (!is_file(Feedeliser::$public_dir . '/' . $row['file']))
            {
                $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): missing cached file {$row['file']}");

                $delete_stmt = static::$feeds_cache->prepare('DELETE FROM file WHERE feed = :feed AND type = :type AND id = :id');
                $delete_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                $delete_stmt->bindValue(':type', $type, SQLITE3_TEXT);
                $delete_stmt->bindValue(':id', $id, SQLITE3_TEXT);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
            else
            {
                $file = $row['file'];
            }
        }

        // If not found in cache
        if (!$file)
        {
            $this->logger->debug("Feedeliser::getPodcastImage($feed, $type, $id): not found in cache");

            if ($type === 'feed')
            {
                $original_url = call_user_func($feed->getPodcastImageCallback(), $xpath);
            }
            else
            {
                $original_url = call_user_func($feed->getPodcastImageItemCallback(), $xpath, $item);
            }

            if ($original_url)
            {
                $image_content = file_get_contents($original_url);
                if (false !== $image_content)
                {
                    $this->logger->debug("Feedeliser::getPodcastImage($feed, $type, $id): found at $original_url");
                    $file = uniqid("{$this->name}_{$type}_") . '.' . pathinfo($original_url, PATHINFO_EXTENSION);
                    file_put_contents(Feedeliser::$public_dir . '/' . $file, $image_content);

                    $set_stmt = static::$feeds_cache->prepare(
                        'INSERT INTO image (feed, type, id, file) ' .
                        'VALUES (:feed, :type, :id, :file)'
                    );
                    $set_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                    $set_stmt->bindValue(':type', $ype, SQLITE3_TEXT);
                    $set_stmt->bindValue(':id', $id, SQLITE3_TEXT);
                    $set_stmt->bindValue(':file', $file, SQLITE3_TEXT);
                    $set_stmt->execute();
                    $set_stmt->close();
                }
                else
                {
                    $this->logger->debug("Feedeliser::getPodcastImage($feed, $type, $id): invalid URL $original_url");
                }
            }
            else
            {
                $this->logger->debug("Feedeliser::getPodcastImage($feed, $type, $id): not found in content");
            }
        }

        if ($file && is_file(Feedeliser::$public_dir . '/' . $file))
        {
            return 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . '/' . Feedeliser::$public_dir . '/' . $file;
        }
        else
        {
            $this->logger->debug("Feedeliser::getPodcastImage($feed, $type, $id): nothing found");
            return '';
        }
    }

    /**
     * Clear cache entries for a feed, last accessed before the feed's cache limit
     * 
     * @param \majetzx\feedeliser\Feed $feed the Feed object
     */
    public function clearCache(Feed $feed)
    {
        $this->openCache();

        // Calculate the date before which entries must be deleted
        $last_access = time() - $feed->getCacheLimit();
        $this->logger->debug("Feedeliser::clearCache($feed): delete cache entries last accessed before " . date('Y-m-d H:i:s', $last_access));
        
        $cache_stmt = static::$feeds_cache->prepare('DELETE FROM feed_entry WHERE feed = :feed AND last_access < :datetime');
        $cache_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
        $cache_stmt->bindValue(':datetime', $last_access, SQLITE3_INTEGER);
        $cache_stmt->execute();
        $this->logger->debug("Feedeliser::clearCache($feed): " . static::$feeds_cache->changes() . " entries deleted");

        // Reclaim empty space
        static::$feeds_cache->exec('VACUUM');
    }

    /**
     * Add namespaces to an XPath object
     *
     * @param \DOMXPath $xpath XPath object
     * @param array $namespaces namespaces array, keys are namespaces, values are URLs
     */
    public static function registerXpathNamespaces(DOMXPath $xpath, array $namespaces)
    {
        foreach ($namespaces as $namespace => $url)
        {
            $xpath->registerNameSpace($namespace, $url);
        }
    }

    /**
     * Replace a text node with another text node
     *
     * @param \DOMDocument $doc the DOM document
     * @param \DOMNode $block the DOM node representing the block
     * @param \DOMXPath $xpath the XPath object
     * @param string $node_path the path to the content node
     * @param string $content the content to replace the original content
     */
    public static function replaceContent(DOMDocument $doc, DOMNode $block, DOMXPath $xpath, string $node_path, string $content)
    {
        $node = $xpath->query($node_path, $block)->item(0);
        static::deleteDomChildren($node->childNodes);
        $text = $doc->createTextNode($content);
        $node->appendChild($text);
    }

    /**
     * Replace a text node with a CDATA node
     *
     * @param \DOMDocument $doc the DOM document
     * @param \DOMNode $block the DOM node representing the block
     * @param \DOMXPath $xpath the XPath object
     * @param string $node_path the path to the content node
     * @param string $content the content to replace the original content
     */
    public static function replaceContentCdata(DOMDocument $doc, DOMNode $block, DOMXPath $xpath, string $node_path, string $content)
    {
        $node = $xpath->query($node_path, $block)->item(0);
        static::deleteDomChildren($node->childNodes);
        $cdata = $doc->createCDATASection($content);
        $node->appendChild($cdata);
    }

    /**
     * Delete children nodes
     *
     * @param \DOMNodeList $children children nodes to delete
     */
    public static function deleteDomChildren(DOMNodeList $children)
    {
        for ($i = $children->length; --$i >= 0; )
        {
            $child = $children->item($i);
            $child->parentNode->removeChild($child);
        }
    }

    /**
     * Detect a paywall and update title if found
     * 
     * A paywall is detected by searching a small string (needle) into a content (haystack)
     * 
     * @param string $title the title to update
     * @param string $haystack the content into which the needle is searched
     * @param string $needle the needle searched in the haystack
     */
    public static function detectPaywall(string &$title, string $haystack, string $needle)
    {
        if (strpos($haystack, $needle) !== false)
        {
            $title = "🔒 $title";
        }
    }

    /**
     * Create a DOMXPath object from an HTML content
     * 
     * @param string $content the HTML content objects are created from
     * @param bool $withXmlPrologue include XML prologue at the beginning of the content
     * @param bool $withLibxmlHtmlOptions load HTML content with Libxml HTML optionns
     * 
     * @return array an array with DOMDocument and DOMXPath objects
     */
    public static function createXPathObject(
        string $content,
        bool $withXmlPrologue = true,
        bool $withLibxmlHtmlOptions = true
    ): array
    {
        $document = new DOMDocument();
        $document->loadHTML(
            ($withXmlPrologue ? static::XML_PROLOGUE : '') . $content,
            $withLibxmlHtmlOptions ? LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD : 0
        );
        $xpath = new DOMXpath($document);
        return [$document, $xpath];
    }
}
