<?php
declare(strict_types=1);

namespace majetzx\feedeliser;

use Psr\Log\LoggerInterface;
use SQLite3;

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
     * Logger
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * SQLite feeds cache
     * @var \SQLite3
     */
    protected $feeds_cache;

    /**
     * Constructor
     * 
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        // Feed name in query string
        $feed_name = filter_input(INPUT_SERVER, 'QUERY_STRING', FILTER_SANITIZE_STRING);

        // No configuration file
        if (!is_file(static::$feeds_confs_dir . "/$feed_name.php"))
        {
            $this->logger->error("Feed \"$feed_name\": missing configuration file");
            return false;
        }

        // Create an anonymous object and generate the feed
        (new Feed(
            $feed_name,
            require static::$feeds_confs_dir . "/$feed_name.php",
            $logger
        ))->generate();
    }

    /**
     * Get content from a URL
     * 
     * @param string $url
     * 
     * @return array an array with the keys "http_body" and "http_code"
     */
    public static function getUrlContent($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Feedeliser/' . static::FEEDELISER_VERSION . ' (+https://github.com/majetzx/feedeliser)');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // empty string to accept all encodings
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Cache-Control: no-cache',
        ));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $http_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'http_body' => $http_body,
            'http_code' => $http_code,
        ];    
    }
}
