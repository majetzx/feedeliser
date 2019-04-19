<?php
declare(strict_types=1);

namespace majetzx\feedeliser;

use Psr\Log\LoggerInterface;

/**
 * Feed class
 */
class Feed
{
    /**
     * Source type: a feed
     * @var string
     */
    const SOURCE_FEED = 'feed';
    
    /**
     * Source type: a web page
     * @var string
     */
    const SOURCE_PAGE = 'page';
    
    /**
     * Default time to keep an item in cache
     * @var int
     */
    public static $default_cache_limit = 7 * 24 * 60 * 60; // one week
    
    /**
     * Feed name
     * @var string
     */
    protected $name;

    /**
     * Feed source type
     * @var string
     * @see self::SOURCE_FEED
     * @see self::SOURCE_PAGE
     */
    protected $source_type;
    
    /**
     * Feed URL
     * @var string
     */
    protected $url;
    
    /**
     * XPath path to get items in the feed or the web page
     * @var string
     */
    protected $items_xpath;
    
    /**
     * Callback to transform an item
     * @var callable
     */
    protected $item_callback;
    
    /**
     * Time to keep an item in cache, defaults to self::$default_cache_limit if missing
     * @var int
     * @see self::$default_cache_limit
     */
    protected $cache_limit;
    
    /**
     * Additional namespaces, in case of a feed source
     * @var string[]
     */
    protected $xml_namespaces = array();

    /**
     * Logger
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Constructor
     * 
     * @param string $name feed name
     * @param array $config feed configuration
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(string $name, array $config, LoggerInterface $logger)
    {
        // Argument: name
        $this->name = $name;

        // Argument: source_type
        if (!isset($config['source_type']))
        {
            throw new \InvalidArgumentException("Missing argument source_type");
        }
        if ($config['source_type'] !== self::SOURCE_FEED && $config['source_type'] !== self::SOURCE_PAGE)
        {
            throw new \InvalidArgumentException("Invalid argument source_type");
        }
        $this->source_type = $config['source_type'];
        
        // Argument: url
        if (!isset($config['url']))
        {
            throw new \InvalidArgumentException("Missing argument url");
        }
        if (!is_string($config['url']))
        {
            throw new \InvalidArgumentException("Invalid argument type url");
        }
        if (filter_var($config['url'], FILTER_VALIDATE_URL) === false)
        {
            throw new \InvalidArgumentException("Invalid argument url");
        }
        $this->url = $config['url'];

        // Argument: items_xpath
        if (!isset($config['items_xpath']))
        {
            throw new \InvalidArgumentException("Missing argument items_xpath");
        }
        if (!is_string($config['items_xpath']))
        {
            throw new \InvalidArgumentException("Invalid argument type items_xpath");
        }
        $this->items_xpath = $config['items_xpath'];

        // Argument: item_callback
        if (!isset($config['item_callback']))
        {
            throw new \InvalidArgumentException("Missing argument item_callback");
        }
        if (!is_callable($config['item_callback']))
        {
            throw new \InvalidArgumentException("Invalid argument type item_callback");
        }
        $this->item_callback = $config['item_callback'];

        // Argument: cache_limit
        if (isset($config['cache_limit']))
        {
            if (!is_int($config['cache_limit']))
            {
                throw new \InvalidArgumentException("Invalid argument type cache_limit");
            }
            $this->cache_limit = (int) $config['cache_limit'];
        }
        else
        {
            $this->cache_limit = static::$default_cache_limit;
        }

        // Argument: xml_namespaces
        if (isset($config['xml_namespaces']))
        {
            if (!is_array($config['xml_namespaces']))
            {
                throw new \InvalidArgumentException("Invalid argument type xml_namespaces");
            }
            $this->xml_namespaces = $config['xml_namespaces'];
        }

        $this->logger = $logger;
    }

    /**
     * Generate the feed
     * 
     * @return mixed false in case of error
     */
    public function generate()
    {
        $url_content = Feedeliser::getUrlContent($this->url);

        // We need a 200 response
        if ($url_content['http_code'] != 200)
        {
            $this->logger->warning("feed \"$this->name\": HTTP code = {$url_content['http_code']}");
            return false;
        }
    }
}