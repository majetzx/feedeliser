<?php
declare(strict_types=1);

namespace majetzx\feedeliser;

use Psr\Log\LoggerInterface;
use DOMDocument, DOMXpath, DOMProcessingInstruction;
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
     * Source type: a JSON page
     * @var string
     */
    const SOURCE_JSON = 'json';

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
     * @see self::SOURCE_JSON
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
     * Callback returning all the items from JSON data, only and required for source_type=SOURCE_JSON
     * Must return an array of arrays, with required key `link` and optional keys `title`, `content`, `time` (timestamp)
     * @var callable
     */
    protected $json_items_callback;

    /**
     * Callback returning values from a JSON item, only and required for source_type=SOURCE_JSON
     * Must return an array with optional keys `title`, `content`, `time` (timestamp)
     * @var callable
     */
    protected $json_item_callback;

    /**
     * Whether the feed is a podcast
     * @var bool
     */
    protected $podcast = false;

    /**
     * For a podcast, callback returning the podcast image, only and required for podcast=true
     * @var callable
     */
    protected $podcast_image_callback;

    /**
     * For a podcast, callback returning an item image, only and required for podcast=true
     * @var callable
     */
    protected $podcast_item_image_callback;

    /**
     * For a podcast, callback to download an item enclosure if generic method fails, only for podcast=true
     * @var callable
     */
    protected $podcast_item_enclosure_callback;

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
     * Logger
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * XPath object used to query the document
     * @var \DOMXPath
     */
    private $process_doc_xpath;

    /**
     * XPath object used to query the current item
     * @var \DOMXPath
     */
    private $process_item_xpath;

    /**
     * DOMNode object for the current item
     * @var \DOMNode
     */
    private $process_item_domnode;

    /**
     * JSON data of the whole document
     * @var mixed
     */
    private $process_doc_json;

    /**
     * JSON data for the current item
     * @var mixed
     */
    private $process_item_json;

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
        $logger->debug("Feed::__construct($name): start");

        // Argument: feedeliser
        $this->feedeliser = $feedeliser;

        // Argument: name
        $this->name = $name;

        // Argument: source_type
        if (isset($config['source_type']))
        {
            if (
                $config['source_type'] !== self::SOURCE_FEED
             && $config['source_type'] !== self::SOURCE_PAGE
             && $config['source_type'] !== self::SOURCE_JSON
            ) {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument source_type");
            }
            $this->source_type = $config['source_type'];
        }

        // Argument: title, SOURCE_PAGE and SOURCE_JSON only
        if ($this->source_type == self::SOURCE_PAGE || $this->source_type == self::SOURCE_JSON)
        {
            if (!isset($config['title']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): missing argument title");
            }
            if (!is_string($config['title']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type title");
            }
            $this->title = $config['title'];
        }
        
        // Argument: url
        if (!isset($config['url']))
        {
            throw new \InvalidArgumentException("Feed::__construct($this): missing argument url");
        }
        if (!is_string($config['url']))
        {
            throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type url");
        }
        if (filter_var($config['url'], FILTER_VALIDATE_URL) === false)
        {
            throw new \InvalidArgumentException("Feed::__construct($this): invalid argument url");
        }
        $this->url = $config['url'];

        // Argument: items_xpath
        if (isset($config['items_xpath']))
        {
            if (!is_string($config['items_xpath']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type items_xpath");
            }
            $this->items_xpath = $config['items_xpath'];
        }
        else if ($this->source_type == self::SOURCE_PAGE)
        {
            throw new \InvalidArgumentException("Feed::__construct($this): missing argument items_xpath for source_type=SOURCE_PAGE");
        }

        // Argument: item_link_xpath
        if (isset($config['item_link_xpath']))
        {
            if (!is_string($config['item_link_xpath']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type item_link_xpath");
            }
            $this->item_link_xpath = $config['item_link_xpath'];
        }
        else if ($this->source_type == self::SOURCE_PAGE)
        {
            throw new \InvalidArgumentException("Feed::__construct($this): missing argument item_link_xpath for source_type=SOURCE_PAGE");
        }

        // Argument: item_link_prefix
        if ($this->source_type == self::SOURCE_PAGE && isset($config['item_link_prefix']))
        {
            if (!is_string($config['item_link_prefix']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type item_link_prefix");
            }
            $this->item_link_prefix = $config['item_link_prefix'];
        }

        // Argument: item_title_xpath
        if (isset($config['item_title_xpath']))
        {
            if (!is_string($config['item_title_xpath']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type item_title_xpath");
            }
            $this->item_title_xpath = $config['item_title_xpath'];
        }

        // Argument: item_content_xpath
        if (isset($config['item_content_xpath']))
        {
            if (!is_string($config['item_content_xpath']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type item_content_xpath");
            }
            $this->item_content_xpath = $config['item_content_xpath'];
        }

        // Argument: item_time_xpath, SOURCE_PAGE only
        if ($this->source_type == self::SOURCE_PAGE && isset($config['item_time_xpath']))
        {
            if (!is_string($config['item_time_xpath']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type item_time_xpath");
            }
            $this->item_time_xpath = $config['item_time_xpath'];
        }

        // Argument: item_callback
        if (isset($config['item_callback']))
        {
            if (!is_callable($config['item_callback']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type item_callback");
            }
            $this->item_callback = $config['item_callback'];
        }

        // Argument: readability
        if (isset($config['readability']))
        {
            $this->readability = (bool) $config['readability'];
        }

        // Argument: json_items_callback, SOURCE_JSON only
        if ($this->source_type == self::SOURCE_JSON)
        {
            if (!isset($config['json_items_callback']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): missing argument json_items_callback for source_type=SOURCE_JSON");
            }
            if (!is_callable($config['json_items_callback']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type json_items_callback");
            }
            $this->json_items_callback = $config['json_items_callback'];
        }

        // Argument: json_item_callback, SOURCE_JSON only
        if ($this->source_type == self::SOURCE_JSON)
        {
            if (!isset($config['json_item_callback']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): missing argument json_item_callback for source_type=SOURCE_JSON");
            }
            if (!is_callable($config['json_item_callback']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type json_item_callback");
            }
            $this->json_item_callback = $config['json_item_callback'];
        }

        // Argument: podcast
        if (isset($config['podcast']))
        {
            $this->podcast = (bool) $config['podcast'];

            // Argument: podcast_image_callback
            if (!isset($config['podcast_image_callback']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): missing argument podcast_image_callback for podcast=true");
            }
            if (!is_callable($config['podcast_image_callback']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type podcast_image_callback");
            }
            $this->podcast_image_callback = $config['podcast_image_callback'];

            // Argument: podcast_item_image_callback
            if (!isset($config['podcast_item_image_callback']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): missing argument podcast_item_image_callback for podcast=true");
            }
            if (!is_callable($config['podcast_item_image_callback']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type podcast_item_image_callback");
            }
            $this->podcast_item_image_callback = $config['podcast_item_image_callback'];
            
            // Argument: podcast_item_enclosure_callback
            if (isset($config['podcast_item_enclosure_callback']))
            {
                if (!is_callable($config['podcast_item_enclosure_callback']))
                {
                    throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type podcast_item_enclosure_callback");
                }
                $this->podcast_item_enclosure_callback = $config['podcast_item_enclosure_callback'];
            }
        }
        
        // Argument: cache_limit
        if (isset($config['cache_limit']))
        {
            if (!is_int($config['cache_limit']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type cache_limit");
            }
            $this->cache_limit = (int) $config['cache_limit'];
        }

        // Argument: xml_namespaces
        if (isset($config['xml_namespaces']))
        {
            if (!is_array($config['xml_namespaces']))
            {
                throw new \InvalidArgumentException("Feed::__construct($this): invalid argument type xml_namespaces");
            }
            $this->xml_namespaces = $config['xml_namespaces'];
        }

        $this->logger = $logger;
    }

    /**
     * Get the feed name
     * 
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the feed source type
     * 
     * @return string
     */
    public function getSourceType(): string
    {
        return $this->source_type;
    }

    /**
     * Get the item callback
     * 
     * @return ?callable
     */
    public function getItemCallback(): ?callable
    {
        return $this->item_callback;
    }

    /**
     * Get the readability setting
     * 
     * @return bool
     */
    public function getReadability(): bool
    {
        return $this->readability;
    }

    /**
     * Get the item callback, for JSON source
     * 
     * @return ?callable
     */
    public function getJsonItemCallback(): ?callable
    {
        return $this->json_item_callback;
    }

    /**
     * Get the podcast setting
     * 
     * @return bool
     */
    public function getPodcast(): bool
    {
        return $this->podcast;
    }

    /**
     * Get the podcast item enclosure callback, if feed is a podcast and callback is defined
     *
     * @return ?callable
     */
    public function getPodcastItemEnclosureCallback(): ?callable
    {
        return $this->podcast_item_enclosure_callback;
    }

    /**
     * Get the cache limit in seconds
     * 
     * @return int
     */
    public function getCacheLimit(): int
    {
        return $this->cache_limit;
    }

    /**
     * Generate the feed
     * 
     * @return bool false in case of error
     */
    public function generate(): bool
    {
        $this->logger->debug("Feed::generate($this): start");

        $url_content = $this->feedeliser->getUrlContent($this->url);

        // We need a 200 response
        if ($url_content['http_code'] != 200)
        {
            $this->logger->warning("Feed::generate($this): invalid HTTP code {$url_content['http_code']}");
            return false;
        }

        // Change encoding if it's not in the target encoding
        if ($this->source_type != self::SOURCE_JSON) {
            $matches = [];
            if (preg_match('/^<\?xml\s+.*encoding="([a-z0-9-]+)".*\?>$/im', $url_content['http_body'], $matches))
            {
                $encoding = $matches[1];
                if (strtoupper($encoding) != strtoupper(self::TARGET_ENCODING))
                {
                    $this->logger->debug("Feed::generate($this): convert from encoding \"$encoding\"");
                    $url_content['http_body'] = preg_replace(
                        '/^(<\?xml\s+.*encoding=")([a-zA-Z0-9-]+)(".*\?>)$/m',
                        '${1}' . self::TARGET_ENCODING . '${3}',
                        $url_content['http_body']
                    );
                    $url_content['http_body'] = iconv($encoding, self::TARGET_ENCODING, $url_content['http_body']);
                }
            }
        }
        $encoding = self::TARGET_ENCODING;

        // Get all items from source
        if ($this->source_type == self::SOURCE_FEED || $this->source_type == self::SOURCE_PAGE) {
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

            // Remove Processing Instructions
            foreach ($doc->childNodes as $node)
            {
                if ($node instanceof DOMProcessingInstruction)
                {
                    $node->parentNode->removeChild($node);
                }
            }

            $this->process_doc_xpath = new DOMXpath($doc);

            // Register additional XML namespaces for XPath queries
            if ($this->source_type == self::SOURCE_FEED)
            {
                Feedeliser::registerXpathNamespaces($this->process_doc_xpath, $this->xml_namespaces);
            }

            $items = $this->process_doc_xpath->query($this->items_xpath);
            if ($items === false)
            {
                $this->logger->warning("Feed::generate($this): invalid \"items_xpath\" parameter");
                return false;
            }
            if (!$items->length)
            {
                $this->logger->warning("Feed::generate($this): no item found");
                return false;
            }
        }
        else if ($this->source_type == self::SOURCE_JSON) {
            $this->process_doc_json = json_decode($url_content['http_body']);
            if ($this->process_doc_json === null)
            {
                $this->logger->warning("Feed::generate($this): can't decode JSON");
                return false;
            }
            $items = call_user_func($this->json_items_callback, $this->feedeliser, $this->process_doc_json);
        }

        // Process each item, according to source type
        $item_num = 0;

        // An RSS feed
        if ($this->source_type == self::SOURCE_FEED)
        {
            foreach ($items as $this->process_item_domnode)
            {
                $item_num++;
                $this->process_item_xpath = new DOMXpath($doc);
                Feedeliser::registerXpathNamespaces($this->process_item_xpath, $this->xml_namespaces);
                $original_link = $original_title = $original_content = '';
                $original_time = 0;

                // Item link: we just need the value, not the XML node
                $link_node = $this->process_item_xpath->query($this->item_link_xpath, $this->process_item_domnode)->item(0);
                if (!$link_node)
                {
                    $this->logger->warning("Feed::generate($this): no link found for item #$item_num");
                    continue;
                }
                // Try the node value
                $original_link = $link_node->nodeValue;
                $link = Feedeliser::cleanLink($original_link);
                if (!$link)
                {
                    $this->logger->warning("Feed::generate($this): empty link for item #$item_num");
                    continue;
                }
                // Replace link, only if different from original
                if ($original_link != $link)
                {
                    Feedeliser::replaceContent(
                        $doc,
                        $this->process_item_domnode,
                        $this->process_item_xpath,
                        $this->item_link_xpath,
                        $link
                    );
                }

                // Item title: we need the XML node to replace its content in some cases
                $title_node = $this->process_item_xpath->query($this->item_title_xpath, $this->process_item_domnode)->item(0);
                if (!$title_node)
                {
                    $this->logger->warning("Feed::generate($this): no title found for item #$item_num ($link)");
                }
                else
                {
                    $original_title = $title_node->nodeValue;
                }

                // Item content: we need the XML node to replace its content
                $content_node = $this->process_item_xpath->query($this->item_content_xpath, $this->process_item_domnode)->item(0);
                if (!$content_node)
                {
                    $this->logger->warning("Feed::generate($this): no content found for item #$item_num ($link)");
                }
                else
                {
                    $original_content = $content_node->nodeValue;
                }

                // Get the item content, from cache if available
                $item_content = $this->feedeliser->getItemContent($this, $link, $original_title, $original_content, $original_time);

                // Replace title and content, with CDATA sections, only if different from original
                if ($item_content['title'] !== $original_title)
                {
                    Feedeliser::replaceContentCdata(
                        $doc,
                        $this->process_item_domnode,
                        $this->process_item_xpath,
                        $this->item_title_xpath,
                        $item_content['title']
                    );
                }
                if ($item_content['content'] !== $original_content)
                {
                    Feedeliser::replaceContentCdata(
                        $doc,
                        $this->process_item_domnode,
                        $this->process_item_xpath,
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
            $xml = $this->getXmlBase();

            foreach ($items as $this->process_item_domnode)
            {
                $item_num++;
                $this->process_item_xpath = new DOMXpath($doc);
                $original_title = $original_content = '';
                $original_time = 0;
                
                // Item link: we just need the value, not the XML node
                $link_node = $this->process_item_xpath->query($this->item_link_xpath, $this->process_item_domnode)->item(0);
                if (!$link_node)
                {
                    $this->logger->warning("Feed::generate($this): no link found for item #$item_num");
                    continue;
                }
                // Get the node value, with optional prefix
                $link = Feedeliser::cleanLink($link_node->nodeValue);
                if ($link && $this->item_link_prefix)
                {
                    $link = $this->item_link_prefix . $link;
                }
                if (!$link || !filter_var($link, FILTER_VALIDATE_URL))
                {
                    $this->logger->warning("Feed::generate($this): empty link for item #$item_num");
                    continue;
                }

                // Item title: optional
                if ($this->item_title_xpath)
                {
                    $title_node = $this->process_item_xpath->query($this->item_title_xpath, $this->process_item_domnode)->item(0);
                    if ($title_node)
                    {
                        $original_title = $title_node->nodeValue;
                    }
                }

                // Item content: optional
                if ($this->item_content_xpath)
                {
                    $content_node = $this->process_item_xpath->query($this->item_content_xpath, $this->process_item_domnode)->item(0);
                    if ($content_node)
                    {
                        $original_content = $content_node->nodeValue;
                    }
                }

                // Item date-time: optional
                if ($this->item_time_xpath)
                {
                    $time_node = $this->process_item_xpath->query($this->item_time_xpath, $this->process_item_domnode)->item(0);
                    if ($time_node)
                    {
                        $ts = strtotime($time_node->nodeValue);
                        if ($ts !== false)
                        {
                            $original_time = $ts;
                        }
                    }
                }
                
                $xml['channel']['item'][] = $this->prepareItemArray($link, $original_title, $original_content, $original_time);
            }

            $output_data = ArrayToXml::convert($xml, 'rss');
        }
        // A JSON page
        else if ($this->source_type == self::SOURCE_JSON)
        {
            $xml = $this->getXmlBase();
            
            foreach ($items as $this->process_item_json)
            {
                $item_num++;
                $original_title = $original_content = '';
                $original_time = 0;

                // Item link: required
                if (!isset($this->process_item_json['link']))
                {
                    $this->logger->error("Feed::generate($this): no link provided for item #$item_num");
                    continue;
                }
                $link = Feedeliser::cleanLink($this->process_item_json['link']);
                if ($link && $this->item_link_prefix)
                {
                    $link = $this->item_link_prefix . $link;
                }
                if (!$link || !filter_var($link, FILTER_VALIDATE_URL))
                {
                    $this->logger->warning("Feed::generate($this): empty link for item #$item_num");
                    continue;
                }

                // Item title: optional
                if (isset($this->process_item_json['title']))
                {
                    $original_title = $this->process_item_json['title'];
                }

                // Item content: optional
                if (isset($this->process_item_json['content']))
                {
                    $original_content = $this->process_item_json['content'];
                }

                // Item date-time: optional
                if (isset($this->process_item_json['time']))
                {
                    $ts = strtotime($this->process_item_json['time']);
                    if ($ts !== false)
                    {
                        $original_time = $ts;
                    }
                }

                $xml['channel']['item'][] = $this->prepareItemArray($link, $original_title, $original_content, $original_time);
            }

            $output_data = ArrayToXml::convert($xml, 'rss');
        }

        $this->logger->debug("Feed::generate($this): $item_num items found");

        header('Content-Type: application/xml; charset=' . self::TARGET_ENCODING);
        echo $output_data;

        // Clears the cache before exiting
        $this->feedeliser->clearCache($this);

        return true;
    }

    /**
     * For non feed-based source, returns an array with XML base elements
     * 
     * @see ArrayToXml
     * 
     * @return array
     */
    protected function getXmlBase(): array
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

        if ($this->podcast)
        {
            $xml['_attributes']['xmlns:itunes'] = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
            $xml['channel']['description'] = $this->title;
            $image = $this->feedeliser->getPodcastImage($this, 'feed', '');
            if ($image)
            {
                $xml['channel']['itunes:image'] = [
                    '_attributes' => [
                        'href' => $image,
                    ],
                ];
            }
            $xml['channel']['language'] = 'en';
            $xml['channel']['itunes:category'] = [
                '_attributes' => [
                    'text' => 'Music',
                ],
            ];
            $xml['channel']['itunes:author'] = 'Feedeliser';
            $xml['channel']['itunes:owner'] = [
                'itunes:email' => 'feedeliser@feedeliser.feedeliser', // fake email
                'itunes:name' => 'Feedeliser',
            ];
            $xml['channel']['itunes:title'] = $this->title;
            $xml['channel']['itunes:block'] = 'Yes';
        }

        return $xml;
    }

    /**
     * Return an item array to be added to the final feed
     * 
     * The original values are used if available, otherwise missing values are retrieved from the item URL
     * 
     * @param string $link the item URL
     * @param string $original_title the item title from the main feed, can be empty
     * @param string $original_content the item content from the main feed, can be empty
     * @param int $original_time the item timestamp from the main feed, can be null
     * 
     * @return array
     */
    protected function prepareItemArray(string $link, string $original_title, string $original_content, int $original_time): array
    {
        // Get the content from the page or from cache if available
        if (!$original_title || !$original_content || !$original_time)
        {
            $json_link = $this->source_type == self::SOURCE_JSON && isset($this->process_item_json['json_link'])
                ? $this->process_item_json['json_link']
                : null;

            $item_content = $this->feedeliser->getItemContent($this, $link, $original_title, $original_content, $original_time, $json_link);

            if (!$original_title && $item_content['title'])
            {
                $original_title = $item_content['title'];
            }
            if (!$original_content && $item_content['content'])
            {
                $original_content = $item_content['content'];
            }
            if (!$original_time && $item_content['time'])
            {
                $original_time = $item_content['time'];
            }
        }

        $item_array = [
            'title' => ['_cdata' => $original_title],
            'link' => $link,
            'description' => ['_cdata' => $original_content],
            'pubDate' => date(DATE_RSS, $original_time),
            'guid' => $link,
        ];

        // Additional tags for podcasts
        if ($this->podcast)
        {
            $this->addPodcastElements($item_array);
        }

        return $item_array;
    }

    /**
     * Add podcast elements to an item's array
     * 
     * @param array &$item_array the array containing the item's base elements
     */
    protected function addPodcastElements(array &$item_array)
    {
        $image = $this->feedeliser->getPodcastImage($this, 'entry', $item_array['link']);
        if ($image)
        {
            $item_array['itunes:image'] = [
                '_attributes' => [
                    'href' => $image,
                ],
            ];
        }

        $item_array['itunes:title'] = $item_array['title'];
        
        $podcast_content = $this->feedeliser->getPodcastItemContent($this, $item_array['link']);
        if ($podcast_content['status'] != 'error')
        {
            $item_array['itunes:duration'] = $podcast_content['duration'];
            $item_array['enclosure'] = [
                '_attributes' => [
                    'url' => $podcast_content['enclosure'],
                    'length' => $podcast_content['length'],
                    'type' => $podcast_content['type'],
                ],
            ];
        }
    }

    /**
     * Call the callback to get a podcast image URL
     * 
     * @return string
     */
    public function callPodcastImageCallback()
    {
        if ($this->source_type == self::SOURCE_JSON) {
            return call_user_func($this->podcast_image_callback, $this->feedeliser, $this->process_doc_json);
        } else {
            return call_user_func($this->podcast_image_callback, $this->feedeliser, $this->process_doc_xpath);
        }
    }

    /**
     * Call the callback to get a podcast item image URL
     * 
     * @param string $id the podcast item URL
     * 
     * @return string
     */
    public function callPodcastItemImageCallback(string $id): string
    {
        if ($this->source_type == self::SOURCE_JSON) {
            return call_user_func($this->podcast_item_image_callback, $this->feedeliser, $this->process_item_json);
        } else {
            return call_user_func($this->podcast_item_image_callback, $this->feedeliser, $this->process_item_xpath, $this->process_item_domnode, $id);
        }
    }

    /**
     * Return the feed name
     * 
     * @return string
     */
    public function __toString(): string
    {
        return $this->getName();
    }
}
