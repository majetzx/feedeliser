<?php
declare(strict_types=1);

namespace majetzx\feedeliser;

use Psr\Log\LoggerInterface;
use DOMDocument, DOMNode, DOMNodeList, DOMXPath, SQLite3;
use andreskrey\Readability\{Readability, Configuration, ParseException};
use Goutte\Client;
use Symfony\Component\HttpClient\HttpClient;

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
     * Minimum size for the podcast images
     * @var int
     */
    const PODCAST_IMAGE_MIN_SIZE = 1400;
    
    /**
     * Maximum size for the podcast images
     * @var int
     */
    const PODCAST_IMAGE_MAX_SIZE = 3000;

    /**
     * User-Agent string, used by cURL and alternative podcast downloader
     * Feedeliser identifies itself as a real browser to bypass bot protections
     * @var string
     */
    const FEEDELISER_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.116 Safari/537.36';

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
     * Perform a network call
     * 
     * @param string $url URL
     * @param ?array $post_content the POST content
     * @param ?string $output_file a file to save the output to
     * 
     * @return array an array with the keys "http_body" and "http_code"
     */
    protected function internalCurlContent(string $url, array $post_content = null, string $output_file = null)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_USERAGENT, static::FEEDELISER_USER_AGENT);
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

        // POST content
        if ($post_content !== null)
        {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_content);
        }

        // Output file
        if ($output_file)
        {
            $ofp = fopen($output_file, 'w');
            curl_setopt($ch, CURLOPT_FILE, $ofp);
        }
        
        $http_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($output_file) {
            fclose($ofp);
        }
        
        $this->logger->debug("Feedeliser::getUrlContent($url): $http_code");

        return [
            'http_body' => $http_body,
            'http_code' => $http_code,
        ];
    }

    /**
     * Get content from a URL
     * 
     * @param string $url URL
     * @param ?string $output_file a file to save the output to
     * 
     * @return array an array with the keys "http_body" and "http_code"
     */
    public function getUrlContent(string $url, string $output_file = null): array
    {
        return $this->internalCurlContent($url, null, $output_file);
    }

    /**
     * Post content to a URL
     * 
     * @param string $url URL
     * @param ?array $content the POST content
     * 
     * @return array an array with the keys "http_body" and "http_code"
     */
    public function postUrlContent(string $url, array $content = null): array
    {
        return $this->internalCurlContent($url, $content, null);
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
     * @param int $original_time the original item time
     * @param string $json_url the JSON item URL
     * 
     * @return array an array with keys "status", "title", "content", "time", and for podcast feeds keys "enclosure_url", "image_url", "duration"
     */
    public function getItemContent(
        Feed $feed,
        string $url,
        string $original_title,
        string $original_content,
        int $original_time,
        string $json_url = null
    ): array
    {
        $this->logger->debug("Feedeliser::getItemContent($feed, $url): start");
        
        $this->openCache();
        $status = $title = $content = '';
        $time = 0;
        $cache_available = true;

        // Check if URL is already in cache
        $get_stmt = static::$feeds_cache->prepare(
            'SELECT title, content, time FROM feed_entry WHERE feed = :feed AND url = :url'
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
                $time = $row['time'];
                
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

            $url_content = $this->getUrlContent($json_url ?? $url);
            if ($url_content['http_code'] == 200)
            {
                $status = 'new';

                if ($feed->getSourceType() != Feed::SOURCE_JSON)
                {
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
                            // time not available in Readability
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
                        call_user_func_array($item_callback, [$url_content['http_body'], &$title, &$content, &$time]);
                    }
                }
                else
                {
                    $json_content = json_decode($url_content['http_body']);
                    if (!$json_content)
                    {
                        $this->logger->warning("Feedeliser::getItemContent($feed, $url): error decoding JSON");
                    }
                    else
                    {
                        // For JSON, reuse original values and pass them to the callback
                        $title = $original_title;
                        $content = $original_content;
                        $time = $original_time;
                        call_user_func_array($feed->getJsonItemCallback(), [$json_content, &$title, &$content, &$time]);
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
                if (!$time)
                {
                    $time = $original_time;
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
                    $this->logger->warning("Feedeliser::getItemContent($feed, $url): empty title and content for URL");
                    $status = 'error';
                }
                
                // Store clean content in cache if available
                if ($status == 'new' && $cache_available)
                {
                    $set_stmt = static::$feeds_cache->prepare(
                        'INSERT INTO feed_entry (feed, url, content, title, time, last_access) ' .
                        'VALUES (:feed, :url, :content, :title, :time, :last_access)'
                    );
                    $set_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                    $set_stmt->bindValue(':url', $url, SQLITE3_TEXT);
                    $set_stmt->bindValue(':content', $content, SQLITE3_TEXT);
                    $set_stmt->bindValue(':title', $title, SQLITE3_TEXT);
                    $set_stmt->bindValue(':time', $time, SQLITE3_INTEGER);
                    $set_stmt->bindValue(':last_access', time(), SQLITE3_INTEGER);
                    $set_stmt->execute();
                    $set_stmt->close();
                }
            }
            else
            {
                $this->logger->warning("Feedeliser::getItemContent($feed, $url): can't get content from URL, invalid HTTP code {$url_content['http_code']}");
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

        return [
            'status' => $status,
            'title' => $title,
            'content' => $content,
            'time' => $time,
        ];
    }

    /**
     * Get a podcast item content, from cache if available or from the URL
     * 
     * @param \majetzx\feedeliser\Feed $feed the Feed object
     * @param string $url the item URL
     * 
     * @return array an array with keys "status", "enclosure", "length", "type", "duration"
     */
    public function getPodcastItemContent(Feed $feed, string $url): array
    {
        $status = $enclosure = $type = '';
        $length = $duration = 0;
        $cache_available = true;

        $this->logger->debug("Feedeliser::getPodcastItemContent($feed, $url): start");

        $get_stmt = static::$feeds_cache->prepare(
            'SELECT enclosure, length, type, duration FROM podcast_entry WHERE feed = :feed AND url = :url'
        );

        // Error using the cache
        if (!$get_stmt)
        {
            $this->logger->warning("Feedeliser::getPodcastItemContent($feed, $url): can't prepare cache statement");
            $cache_available = false;
        }
        
        if ($cache_available)
        {
            $get_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
            $get_stmt->bindValue(':url', $url, SQLITE3_TEXT);
            $result = $get_stmt->execute();
            $row = $result->fetchArray();
            $result->finalize();
            $get_stmt->close();

            if ($row)
            {
                $this->logger->debug("Feedeliser::getPodcastItemContent($feed, $url): found in cache");

                $status = 'cache';
                $enclosure = $row['enclosure'];
                $length = $row['length'];
                $type = $row['type'];
                $duration = $row['duration'];
            }
        }

        // If not found in cache, get URL content with youtube-dl
        if ($status != 'cache')
        {
            $this->logger->debug("Feedeliser::getPodcastItemContent($feed, $url): not found in cache");

            $enclosure = uniqid("{$feed->getName()}_enclosure_"); // no extension here
            $output = $return_var = null;
            exec('youtube-dl -o ' . escapeshellarg(static::$public_dir . '/' . $enclosure) . ' ' . escapeshellarg($url), $output, $return_var);

            if (0 === $return_var && is_file(static::$public_dir . '/' . $enclosure))
            {
                $status = 'new';
            }
            else
            {
                $this->logger->warning(
                    "Feedeliser::getPodcastItemContent($feed, $url): error downloading with youtube-dl",
                    [
                        'output' => $output,
                        'return_var' => $return_var,
                    ]
                );
                
                // If feed has a fallback callback
                $enclosure_callback = $feed->getPodcastItemEnclosureCallback();
                if ($enclosure_callback)
                {
                    $this->logger->debug("Feedeliser::getPodcastItemContent($feed, $url): trying fallback callback");
                    if (call_user_func($enclosure_callback, $this, $url, static::$public_dir . '/' . $enclosure)
                        && is_file(static::$public_dir . '/' . $enclosure))
                    {
                        $status = 'new';
                    }
                    else
                    {
                        $this->logger->warning("Feedeliser::getPodcastItemContent($feed, $url): error downloading with callback");
                    }
                }
            }
            
            // Saves in cache if downloaded
            if ($status == 'new')
            {
                // Add extension
                $extension = $this->guessFileExtension(static::$public_dir . '/' . $enclosure);
                if ($extension)
                {
                    rename(static::$public_dir . '/' . $enclosure, static::$public_dir . '/' . $enclosure . '.' . $extension);
                    $enclosure .= ".$extension";
                }
                
                $length = filesize(static::$public_dir . '/' . $enclosure);
                $type = $this->getFileType(static::$public_dir . '/' . $enclosure);
                
                // Duration
                $output = $return_var = null;
                exec('mediainfo --Output=JSON ' . escapeshellarg(static::$public_dir . '/' . $enclosure), $output, $return_var);
                if (0 === $return_var)
                {
                    $mediainfo = json_decode(implode('', $output));
                    $duration = round($mediainfo->media->track[0]->Duration);
                }
                else
                {
                    $this->logger->warning(
                        "Feedeliser::getPodcastItemContent($feed, $url): error getting enclosure duration",
                        [
                            'output' => $output,
                            'return_var' => $return_var,
                        ]
                    );
                }
                
                if ($cache_available)
                {
                    $set_stmt = static::$feeds_cache->prepare(
                        'INSERT INTO podcast_entry (feed, url, enclosure, length, type, duration) ' .
                        'VALUES (:feed, :url, :enclosure, :length, :type, :duration)'
                    );
                    $set_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                    $set_stmt->bindValue(':url', $url, SQLITE3_TEXT);
                    $set_stmt->bindValue(':enclosure', $enclosure, SQLITE3_TEXT);
                    $set_stmt->bindValue(':length', $length, SQLITE3_INTEGER);
                    $set_stmt->bindValue(':type', $type, SQLITE3_TEXT);
                    $set_stmt->bindValue(':duration', $duration, SQLITE3_INTEGER);
                    $set_stmt->execute();
                    $set_stmt->close();
                }
            }
            else
            {
                $this->logger->error("Feedeliser::getPodcastItemContent($feed, $url): no enclosure found");
                $status = 'error';
            }
        }

        return [
            'status' => $status,
            'enclosure' => 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . '/' . static::$public_dir . '/' . $enclosure,
            'length' => $length,
            'type' => $type,
            'duration' => $duration,
        ];
    }

    /**
     * Return a podcast image URL, from cache if available or from a feed/item content
     * 
     * @param \majetzx\feedeliser\Feed $feed the Feed object
     * @param string $type image type, "feed" or "entry"
     * @param string $id empty for "feed" type, item URL for "entry" type
     * 
     * @return string image full URL, or empty string in case of error
     */
    public function getPodcastImage(Feed $feed, string $type, string $id): string
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
            $this->logger->debug("Feedeliser::getPodcastImage($feed, $type, $id): found in cache at {$row['file']}");
            // Found in cache but missing file
            if (!is_file(static::$public_dir . '/' . $row['file']))
            {
                $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): missing cached file {$row['file']}");

                $delete_stmt = static::$feeds_cache->prepare('DELETE FROM image WHERE feed = :feed AND type = :type AND id = :id');
                if ($delete_stmt)
                {
                    $delete_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                    $delete_stmt->bindValue(':type', $type, SQLITE3_TEXT);
                    $delete_stmt->bindValue(':id', $id, SQLITE3_TEXT);
                    $delete_stmt->execute();
                    $delete_stmt->close();
                }
                else
                {
                    $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): can't prepare delete statement");
                }
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
                $original_url = $feed->callPodcastImageCallback();
            }
            else
            {
                $original_url = $feed->callPodcastItemImageCallback($id);
            }

            if ($original_url)
            {
                $image_response = $this->getUrlContent($original_url); // no direct write to file, in case resize fails
                if ($image_response['http_code'] == 200)
                {
                    $this->logger->debug("Feedeliser::getPodcastImage($feed, $type, $id): found at $original_url");
                    $file = uniqid("{$feed->getName()}_{$type}_");
                    $write = file_put_contents(static::$public_dir . '/' . $file, $image_response['http_body']);

                    if (false !== $write && 0 < $write)
                    {
                        // We need a square PNG and JPEG file
                        $imagesize = getimagesize(static::$public_dir . '/' . $file);
                        if (is_array($imagesize))
                        {
                            $valid_image = true;
                            switch ($imagesize[2])
                            {
                                case IMAGETYPE_PNG:
                                    $extension = 'png';
                                    $fn_imagecreatefrom = 'imagecreatefrompng';
                                    $fn_imagecreateto = 'imagepng';
                                    $quality = 0;
                                    break;
                                
                                case IMAGETYPE_JPEG:
                                    $extension = 'jpg';
                                    $fn_imagecreatefrom = 'imagecreatefromjpeg';
                                    $fn_imagecreateto = 'imagejpeg';
                                    $quality = 100;
                                    break;

                                default:
                                    $valid_image = false;
                                    break;
                            }

                            if ($valid_image)
                            {
                                rename(static::$public_dir . '/' . $file, static::$public_dir . '/' . $file . '.' . $extension);
                                $file .= ".$extension";
    
                                // Resize image if square and necessary
                                if ($imagesize[0] == $imagesize[1])
                                {
                                    if ($imagesize[0] < static::PODCAST_IMAGE_MIN_SIZE || $imagesize[0] > static::PODCAST_IMAGE_MAX_SIZE)
                                    {
                                        // Minimum size
                                        if ($imagesize[0] < static::PODCAST_IMAGE_MIN_SIZE)
                                        {
                                            $new_size = static::PODCAST_IMAGE_MIN_SIZE;
                                        }
                                        // Maximum size
                                        else
                                        {
                                            $new_size = static::PODCAST_IMAGE_MAX_SIZE;
                                        }
                                        
                                        $new_image = imagecreatetruecolor($new_size, $new_size);
                                        $old_image = $fn_imagecreatefrom(static::$public_dir . '/' . $file);
                                        imagecopyresampled($new_image, $old_image, 0, 0, 0, 0, $new_size, $new_size, $imagesize[0], $imagesize[1]);
                                        imagedestroy($old_image);
                                        $resize_return = $fn_imagecreateto($new_image, static::$public_dir . '/' . $file, $quality);
                                        
                                        // Per PHP documentation, we can get true even if it fails
                                        if (!$resize_return || !filesize(static::$public_dir . '/' . $file))
                                        {
                                            $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): error resizing image, keeping original image");
                                            file_put_contents(static::$public_dir . '/' . $file, $image_response['http_body']);
                                        }
                                    }
                                }
                                else
                                {
                                    $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): $file is not a square image");
                                }
                            }
                            else
                            {
                                $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): $file is not a valid image type ($imagesize[2])");
                            }
                        }
                        else
                        {
                            $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): can't get size of image $file");
                        }
                        
                        $set_stmt = static::$feeds_cache->prepare(
                            'INSERT INTO image (feed, type, id, file) ' .
                            'VALUES (:feed, :type, :id, :file)'
                        );
                        $set_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                        $set_stmt->bindValue(':type', $type, SQLITE3_TEXT);
                        $set_stmt->bindValue(':id', $id, SQLITE3_TEXT);
                        $set_stmt->bindValue(':file', $file, SQLITE3_TEXT);
                        $set_stmt->execute();
                        $set_stmt->close();
                    }
                    else
                    {
                        $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): write error to file $file");
                    }
                }
                else
                {
                    $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): invalid URL $original_url");
                }
            }
            else
            {
                $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): not found in content");
            }
        }

        if ($file && is_file(static::$public_dir . '/' . $file))
        {
            return 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . '/' . static::$public_dir . '/' . $file;
        }
        else
        {
            $this->logger->warning("Feedeliser::getPodcastImage($feed, $type, $id): nothing found");
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
        
        // For a podcast, we need URLs to delete files alongside
        if ($feed->getPodcast())
        {
            $this->logger->debug("Feedeliser::clearCache($feed): feed is a podcast");

            $select_feed_entry_stmt = static::$feeds_cache->prepare('SELECT url FROM feed_entry WHERE feed = :feed AND last_access < :datetime');
            $select_feed_entry_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
            $select_feed_entry_stmt->bindValue(':datetime', $last_access, SQLITE3_INTEGER);
            $result_select_feed_entry = $select_feed_entry_stmt->execute();
            while ($feed_entry_row = $result_select_feed_entry->fetchArray())
            {
                // We should have only one enclosure and one image per podcast entry
                // but we loop on results to be sure to delete everything

                $select_podcast_entry_stmt = static::$feeds_cache->prepare('SELECT enclosure FROM podcast_entry WHERE feed = :feed AND url = :url');
                $select_podcast_entry_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                $select_podcast_entry_stmt->bindValue(':url', $feed_entry_row['url'], SQLITE3_TEXT);
                $result_select_podcast_entry = $select_podcast_entry_stmt->execute();
                while ($podcast_entry_row = $result_select_podcast_entry->fetchArray())
                {
                    if (is_file(static::$public_dir . '/' . $podcast_entry_row['enclosure']))
                    {
                        unlink(static::$public_dir . '/' . $podcast_entry_row['enclosure']);
                    }
                }
                $result_select_podcast_entry->finalize();
                $select_podcast_entry_stmt->close();

                $delete_podcast_entry_stmt = static::$feeds_cache->prepare('DELETE FROM podcast_entry WHERE feed = :feed AND url = :url');
                $delete_podcast_entry_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                $delete_podcast_entry_stmt->bindValue(':url', $feed_entry_row['url'], SQLITE3_TEXT);
                $delete_podcast_entry_stmt->execute();
                $nb_podcast_entry = static::$feeds_cache->changes();
                $delete_podcast_entry_stmt->close();

                $select_image_stmt = static::$feeds_cache->prepare('SELECT file FROM image WHERE feed = :feed AND type = "entry" AND id = :url');
                $select_image_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                $select_image_stmt->bindValue(':url', $feed_entry_row['url'], SQLITE3_TEXT);
                $result_select_image = $select_image_stmt->execute();
                while ($image_row = $result_select_image->fetchArray())
                {
                    if (is_file(static::$public_dir . '/' . $image_row['file']))
                    {
                        unlink(static::$public_dir . '/' . $image_row['file']);
                    }
                }
                $result_select_image->finalize();
                $select_image_stmt->close();

                $delete_image_stmt = static::$feeds_cache->prepare('DELETE FROM image WHERE feed = :feed AND type = "entry" AND id = :url');
                $delete_image_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                $delete_image_stmt->bindValue(':url', $feed_entry_row['url'], SQLITE3_TEXT);
                $delete_image_stmt->execute();
                $nb_image = static::$feeds_cache->changes();
                $delete_image_stmt->close();

                $delete_feed_entry_stmt = static::$feeds_cache->prepare('DELETE FROM feed_entry WHERE feed = :feed AND url = :url');
                $delete_feed_entry_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
                $delete_feed_entry_stmt->bindValue(':url', $feed_entry_row['url'], SQLITE3_TEXT);
                $delete_feed_entry_stmt->execute();
                $delete_feed_entry_stmt->close();

                $this->logger->debug("Feedeliser::clearCache($feed, {$feed_entry_row['url']}): $nb_podcast_entry podcast entries deleted, $nb_image images deleted");
            }
            $result_select_feed_entry->finalize();
            $select_feed_entry_stmt->close();
        }
        else
        {
            $cache_stmt = static::$feeds_cache->prepare('DELETE FROM feed_entry WHERE feed = :feed AND last_access < :datetime');
            $cache_stmt->bindValue(':feed', $feed->getName(), SQLITE3_TEXT);
            $cache_stmt->bindValue(':datetime', $last_access, SQLITE3_INTEGER);
            $cache_stmt->execute();
            $cache_stmt->close();
            $this->logger->debug("Feedeliser::clearCache($feed): " . static::$feeds_cache->changes() . " entries deleted");
        }

        // Reclaim empty space
        static::$feeds_cache->exec('VACUUM');
    }

    /**
     * Clean a link
     * 
     * @param string $link a URL to clean
     * 
     * @return string cleaned URL
     */
    public static function cleanLink(string $link): string
    {
        // Some links start with "http://https://"
        $matches = array();
        if (preg_match('/^http:\/\/(https:\/\/.*)$/', $link, $matches)) {
            $link = $matches[1];
        }

        return $link;
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
    
    /**
     * Get a file MIME type, with misdetection correction
     * 
     * @see mime_content_type()
     * 
     * @param string $filepath the file to get the type
     * 
     * @return string the type
     */
    public function getFileType(string $filepath): string
    {
        $type = mime_content_type($filepath);
        // Handle misdetections
        if ($type == 'application/x-font-gdos') // MP3
        {
            $type = 'audio/mpeg';
        }
        return $type;
    }

    /**
     * Guess a file extension
     *
     * @param string $filepath the file to guess
     *
     * @return string the extension
     */
    public function guessFileExtension(string $filepath): string
    {
        $type = $this->getFileType($filepath);
        switch($type)
        {
            case 'image/jpeg': $extension = 'jpg'; break;
            case 'image/png': $extension = 'png'; break;
            case 'audio/mpeg': $extension = 'mp3'; break;
            case 'audio/x-m4a': $extension = 'm4a'; break;
            default:
                $this->logger->warning("Feedeliser::guessFileExtension($filepath): unknown type $type");
                $extension = '';
                break;
        }
        return $extension;
    }

    /**
     * Download a podcast enclosure with an alternative downloader
     * Useful when youtube-dl fails to download
     * 
     * @param string $downloader_url download site URL
     * @param string $form_submit_button download button text
     * @param string $url_input URL input name
     * @param string $result_download_type type of download link detection, "filter" for CSS selector, anything else for link text
     * @param string $result_download_name content for the download link detection, CSS selector or link text
     * @param string $enclosure_source_url enclosure URL
     * @param string $enclosure_destination_file destination filepath
     * 
     * @return bool download successful or not
     */
    public function altDownloadPodcastEnclosure(
        string $downloader_url,
        string $form_submit_button,
        string $url_input,
        string $result_download_type,
        string $result_download_name,
        string $enclosure_source_url,
        string $enclosure_destination_file
    ) {
        $client = new Client(HttpClient::create([
            'headers' => [
                'User-Agent' => static::FEEDELISER_USER_AGENT,
            ],
        ]));
        $crawler = $client->request('GET', $downloader_url);
        $form = $crawler->selectButton($form_submit_button)->form();
        $crawler = $client->submit($form, array($url_input => $enclosure_source_url));
        
        if ($result_download_type == 'filter')
        {
            $link_crawler = $crawler->filter($result_download_name);
        }
        else
        {
            $link_crawler = $crawler->selectLink($result_download_name);
        }
        if (!$link_crawler || !$link_crawler->count())
        {
            $this->logger->warning("Feedeliser::altDownloadPodcastEnclosure($enclosure_source_url, $enclosure_destination_file): error getting link crawler", [
                'src' => (string) $client->getResponse(), // temporary
            ]);
            return false;
        }
        
        $link = $link_crawler->link();
        if (!$link)
        {
            $this->logger->warning("Feedeliser::altDownloadPodcastEnclosure($enclosure_source_url, $enclosure_destination_file): error getting link from crawler");
            return false;
        }
        
        $uri = $link->getUri();
        if (!$uri || !filter_var($uri, FILTER_VALIDATE_URL))
        {
            $this->logger->warning("Feedeliser::altDownloadPodcastEnclosure($enclosure_source_url, $enclosure_destination_file): error getting link URL");
            return false;
        }
        
        $http_content = $this->getUrlContent($uri, $enclosure_destination_file);
        if ($http_content['http_code'] != 200)
        {
            $this->logger->warning("Feedeliser::altDownloadPodcastEnclosure($enclosure_source_url, $enclosure_destination_file): error downloading source URL (HTTP code {$http_content['http_code']})");
            return false;
        }
        
        return true;
    }
}
