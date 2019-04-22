<?php
declare(strict_types=1);

namespace majetzx\feedeliser;

use Psr\Log\LoggerInterface;
use DOMDocument, DOMXpath;
use Spatie\ArrayToXml\ArrayToXml;

/**
 * Feed class
 */
class Feed
{
    /**
     * The target encoding
     * @var string
     */
    const TARGET_ENCODING = 'UTF-8';

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
     * Feedeliser object
     * @var \majetzx\feedeliser\Feedeliser
     */
    protected $feedeliser;

    /**
     * Feed name
     * @var string
     */
    protected $name;

    /**
     * Feed source type, default is feed
     * @var string
     * @see self::SOURCE_FEED
     * @see self::SOURCE_PAGE
     */
    protected $source_type = self::SOURCE_FEED;
    
    /**
     * Feed title, only (and required) for source_type=SOURCE_PAGE
     * @var string
     */
    protected $title;

    /**
     * Feed URL
     * @var string
     */
    protected $url;
    
    /**
     * XPath path to get items in the feed or the web page, required for source_type=SOURCE_PAGE
     * @var string
     */
    protected $items_xpath = '//item';
    
    /**
     * XPath path to get item link, required for source_type=SOURCE_PAGE
     * @var string
     */
    protected $item_link_xpath = './link';

    /**
     * Prefix added to URLs found by $item_link_xpath property, only for source_type=SOURCE_PAGE
     * @var string
     */
    protected $item_link_prefix;

    /**
     * XPath path to get item title, optional for source_type=SOURCE_PAGE
     * @var string
     */
    protected $item_title_xpath = './title';

    /**
     * XPath path to get item content, optional for source_type=SOURCE_PAGE
     * @var string
     */
    protected $item_content_xpath = './description';

    /**
     * XPath path to get item date-time, only for source_type=SOURCE_PAGE, optional
     * @var string
     */
    protected $item_time_xpath;

    /**
     * Callback to transform an item
     * @var callable
     */
    protected $item_callback;
    
    /**
     * Use Readability to clean items content
     * @var bool
     */
    protected $readability = true;

    /**
     * Time to keep an item in cache
     * @var int
     */
    protected $cache_limit = 7 * 24 * 60 * 60; // one week
    
    /**
     * Additional namespaces, in case of a feed source
     * @var string[]
     */
    protected $xml_namespaces = array();

    /**
     * A callback called to finalize the data
     * @var callable
     */
    protected $finalize;

    /**
     * Logger
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Constructor
     * 
     * @param \majetzx\feedeliser\Feedeliser $feedeliser
     * @param string $name feed name
     * @param array $config feed configuration
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(Feedeliser $feedeliser, string $name, array $config, LoggerInterface $logger)
    {
        // Argument: feedeliser
        $this->feedeliser = $feedeliser;

        // Argument: name
        $this->name = $name;

        // Argument: source_type
        if (isset($config['source_type']))
        {
            if ($config['source_type'] !== self::SOURCE_FEED && $config['source_type'] !== self::SOURCE_PAGE)
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument source_type");
            }
            $this->source_type = $config['source_type'];
        }

        // Argument: title, SOURCE_PAGE only
        if ($this->source_type == static::SOURCE_PAGE)
        {
            if (!isset($config['title']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": missing argument title");
            }
            if (!is_string($config['title']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type title");
            }
            $this->title = $config['title'];
        }
        
        // Argument: url
        if (!isset($config['url']))
        {
            throw new \InvalidArgumentException("Feed \"$this->name\": missing argument url");
        }
        if (!is_string($config['url']))
        {
            throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type url");
        }
        if (filter_var($config['url'], FILTER_VALIDATE_URL) === false)
        {
            throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument url");
        }
        $this->url = $config['url'];

        // Argument: items_xpath
        if (isset($config['items_xpath']))
        {
            if (!is_string($config['items_xpath']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type items_xpath");
            }
            $this->items_xpath = $config['items_xpath'];
        }
        else if ($this->source_type == static::SOURCE_PAGE)
        {
            throw new \InvalidArgumentException("Feed \"$this->name\": missing argument items_xpath for source_type=SOURCE_PAGE");
        }

        // Argument: item_link_xpath
        if (isset($config['item_link_xpath']))
        {
            if (!is_string($config['item_link_xpath']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type item_link_xpath");
            }
            $this->item_link_xpath = $config['item_link_xpath'];
        }
        else if ($this->source_type == static::SOURCE_PAGE)
        {
            throw new \InvalidArgumentException("Feed \"$this->name\": missing argument item_link_xpath for source_type=SOURCE_PAGE");
        }

        // Argument: item_link_prefix
        if ($this->source_type == static::SOURCE_PAGE && isset($config['item_link_prefix']))
        {
            if (!is_string($config['item_link_prefix']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type item_link_prefix");
            }
            $this->item_link_prefix = $config['item_link_prefix'];
        }

        // Argument: item_title_xpath
        if (isset($config['item_title_xpath']))
        {
            if (!is_string($config['item_title_xpath']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type item_title_xpath");
            }
            $this->item_title_xpath = $config['item_title_xpath'];
        }

        // Argument: item_content_xpath
        if (isset($config['item_content_xpath']))
        {
            if (!is_string($config['item_content_xpath']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type item_content_xpath");
            }
            $this->item_content_xpath = $config['item_content_xpath'];
        }

        // Argument: item_time_xpath, SOURCE_PAGE only
        if ($this->source_type == static::SOURCE_PAGE && isset($config['item_time_xpath']))
        {
            if (!is_string($config['item_time_xpath']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type item_time_xpath");
            }
            $this->item_time_xpath = $config['item_time_xpath'];
        }

        // Argument: item_callback
        if (isset($config['item_callback']))
        {
            if (!is_callable($config['item_callback']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type item_callback");
            }
            $this->item_callback = $config['item_callback'];
        }

        // Argument: readability
        if (isset($config['readability']))
        {
            $this->readability = (bool) $config['readability'];
        }
        
        // Argument: cache_limit
        if (isset($config['cache_limit']))
        {
            if (!is_int($config['cache_limit']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type cache_limit");
            }
            $this->cache_limit = (int) $config['cache_limit'];
        }

        // Argument: xml_namespaces
        if (isset($config['xml_namespaces']))
        {
            if (!is_array($config['xml_namespaces']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type xml_namespaces");
            }
            $this->xml_namespaces = $config['xml_namespaces'];
        }

        // Argument: finalize
        if (isset($config['finalize']))
        {
            if (!is_callable($config['finalize']))
            {
                throw new \InvalidArgumentException("Feed \"$this->name\": invalid argument type finalize");
            }
            $this->finalize = $config['finalize'];
        }

        $this->logger = $logger;
    }

    /**
     * Get the feed name
     * 
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the item callback
     * 
     * @return callable
     */
    public function getItemCallback()
    {
        return $this->item_callback;
    }

    /**
     * Get the readability setting
     * 
     * @return bool
     */
    public function getReadability()
    {
        return $this->readability;
    }

    /**
     * Generate the feed
     * 
     * @return mixed false in case of error
     */
    public function generate(): bool
    {
        $url_content = $this->feedeliser->getUrlContent($this->url);

        // We need a 200 response
        if ($url_content['http_code'] != 200)
        {
            $this->logger->warning("Feed \"$this->name\": invalid HTTP code {$url_content['http_code']}");
            return false;
        }

        // Change encoding if it's not in the target encoding
        $matches = [];
        if (preg_match('/^<\?xml\s+.*encoding="([a-z0-9-]+)".*\?>$/im', $url_content['http_body'], $matches))
        {
            $encoding = $matches[1];
            if (strtoupper($encoding) != strtoupper(static::TARGET_ENCODING))
            {
                $url_content['http_body'] = preg_replace(
                    '/^(<\?xml\s+.*encoding=")([a-z0-9-]+)(".*\?>)$/m',
                    '${1}' . static::TARGET_ENCODING . '${3}',
                    $url_content['http_body']
                );
                $url_content['http_body'] = iconv($encoding, static::TARGET_ENCODING, $url_content['http_body']);
            }
        }
        $encoding = static::TARGET_ENCODING;

        // Disable standard XML errors
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();

        // Load content according to source type
        if ($this->source_type == self::SOURCE_FEED)
        {
            $doc->loadXML($url_content['http_body']);
        }
        else if ($this->source_type == self::SOURCE_PAGE)
        {
            $doc->loadHTML($url_content['http_body']);
        }

        $doc_xpath = new DOMXpath($doc);

        // Register additional XML namespaces for XPath queries
        if ($this->source_type == self::SOURCE_FEED)
        {
            Feedeliser::registerXpathNamespaces($doc_xpath, $this->xml_namespaces);
        }

        // Get all items, if any, call the callback on each one
        $items = $doc_xpath->query($this->items_xpath);
        if ($items === false)
        {
            $this->logger->warning("Feed \"$this->name\": invalid \"items_xpath\" parameter");
            return false;
        }
        if (!$items->length)
        {
            $this->logger->warning("Feed \"$this->name\": no item found");
            return false;
        }

        $item_num = 0;

        // An RSS feed
        if ($this->source_type == self::SOURCE_FEED)
        {
            foreach ($items as $item)
            {
                $item_num++;
                $item_xpath = new DOMXpath($doc);
                Feedeliser::registerXpathNamespaces($item_xpath, $this->xml_namespaces);
                $original_title = $original_content = '';

                // Item link: we just need the value, not the XML node
                $link_node = $item_xpath->query($this->item_link_xpath, $item)->item(0);
                if (!$link_node)
                {
                    $this->logger->warning("Feed \"$this->name\": no link found for item #$item_num");
                    continue;
                }
                // Try the node value
                $link = $link_node->nodeValue;
                if (!$link)
                {
                    $this->logger->warning("Feed \"$this->name\": empty link for item #$item_num");
                    continue;
                }

                // Item title: we need the XML node to replace its content in some cases
                $title_node = $item_xpath->query($this->item_title_xpath, $item)->item(0);
                if (!$title_node)
                {
                    $this->logger->warning("Feed \"$this->name\": no title found for item #$item_num ($link)");
                }
                else
                {
                    $original_title = $title_node->nodeValue;
                }

                // Item content: we need the XML node to replace its content
                $content_node = $item_xpath->query($this->item_content_xpath, $item)->item(0);
                if (!$content_node)
                {
                    $this->logger->warning("Feed \"$this->name\": no content found for item #$item_num ($link)");
                }
                else
                {
                    $original_content = $content_node->nodeValue;
                }

                // Get the item content, from cache if available
                $item_content = $this->feedeliser->getItemContent($this, $link, $original_title, $original_content);

                // Replace title and content, with CDATA sections, only if different from original
                if ($item_content['title'] !== $original_title)
                {
                    Feedeliser::replaceContentCdata(
                        $doc,
                        $item,
                        $item_xpath,
                        $this->item_title_xpath,
                        $item_content['title']
                    );
                }
                if ($item_content['content'] !== $original_content)
                {
                    Feedeliser::replaceContentCdata(
                        $doc,
                        $item,
                        $item_xpath,
                        $this->item_content_xpath,
                        $item_content['content']
                    );
                }
            }

            $output_data = $doc->saveXML();
        }
        // A web page
        else if ($this->source_type == self::SOURCE_PAGE)
        {
            $xml = [
                '_attributes' => ['version' => '2.0'],
                'channel' => [
                    'title' => $this->title,
                    'link' => $this->url,
                    'description' => '',
                    'item' => [],
                ],
            ];

            foreach ($items as $item)
            {
                $item_num++;
                $item_xpath = new DOMXpath($doc);
                $original_title = $original_content = '';
                $original_time = time();
                
                // Item link: we just need the value, not the XML node
                $link_node = $item_xpath->query($this->item_link_xpath, $item)->item(0);
                if (!$link_node)
                {
                    $this->logger->warning("Feed \"$this->name\": no link found for item #$item_num");
                    continue;
                }
                // Get the node value, with optional prefix
                $link = $link_node->nodeValue;
                if ($link && $this->item_link_prefix)
                {
                    $link = $this->item_link_prefix . $link;
                }
                if (!$link || !filter_var($link, FILTER_VALIDATE_URL))
                {
                    $this->logger->warning("Feed \"$this->name\": empty link for item #$item_num");
                    continue;
                }

                // Item title: optional
                if ($this->item_title_xpath)
                {
                    $title_node = $item_xpath->query($this->item_title_xpath, $item)->item(0);
                    if ($title_node)
                    {
                        $original_title = $title_node->nodeValue;
                    }
                }

                // Item content: optional
                if ($this->item_content_xpath)
                {
                    $content_node = $item_xpath->query($this->item_content_xpath, $item)->item(0);
                    if ($content_node)
                    {
                        $original_content = $content_node->nodeValue;
                    }
                }

                // Item date-time: optional
                if ($this->item_time_xpath)
                {
                    $time_node = $item_xpath->query($this->item_time_xpath, $item)->item(0);
                    if ($time_node)
                    {
                        $ts = strtotime($time_node->nodeValue);
                        if ($ts !== false)
                        {
                            $original_time = $ts;
                        }
                    }
                }
                
                // Get the content from the page or from cache if available
                if (!$original_title || !$original_content)
                {
                    $item_content = $this->feedeliser->getItemContent($this, $link, $original_title, $original_content);

                    if (!$original_title && $item_content['title'])
                    {
                        $original_title = $item_content['title'];
                    }
                    if (!$original_content && $item_content['content'])
                    {
                        $original_content = $item_content['content'];
                    }
                }

                $xml['channel']['item'][] = [
                    'title' => ['_cdata' => $original_title],
                    'link' => $link,
                    'description' => ['_cdata' => $original_content],
                    'pubDate' => date(DATE_RSS, $original_time),
                    'guid' => $link,
                ];
            }

            $output_data = ArrayToXml::convert($xml, 'rss');
        }

        // Optional finalizer
        if ($this->finalize)
        {
            $output_data = call_user_func($this->finalize, $output_data);
        }

        header('Content-Type: application/xml; charset=' . static::TARGET_ENCODING);
        echo $output_data;

        return true;
    }
}
