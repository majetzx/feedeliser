# Feedeliser

**Transform a web page into an RSS feed, or make a complete feed from a truncated one**

## Installation

Use `git` to clone the project.

Use `composer` to install dependencies: `composer install`.

Create the following directories:
- `datas` contains cache, cookies and logs;
- `feeds` contains feeds configurations;
- `public` contains public podcast files (enclosures and images).

Create a SQLite file for caching feeds/podcasts datas, in `datas/feeds.sqlite`.
Use table creation queries below.

Create feeds configuration files in the `feeds` directory, as described below.

Configure the web server, a dedicated domain with HTTP Authentication is prefered.

For podcast feeds, [youtube-dl](https://ytdl-org.github.io/youtube-dl/index.html) and [mediainfo](https://mediaarea.net/en/MediaInfo) are required, they must be in the `PATH`.

## SQLite cache

Create a file named `feeds.sqlite` in the `datas` directory.
Create the tables with the queries below.

```
CREATE TABLE IF NOT EXISTS feed_entry (feed TEXT, url TEXT, content TEXT, title TEXT, time INTEGER, last_access INTEGER);
CREATE TABLE IF NOT EXISTS podcast_entry (feed TEXT, url TEXT, enclosure TEXT, length INTEGER, type TEXT, duration INTEGER);
CREATE TABLE IF NOT EXISTS image (feed TEXT, type TEXT, id TEXT, file TEXT);
```

## Feed configuration files

Each feed has its own configuration file, located in the `feeds` directory.

A configuration file is basically a PHP file returning an array, A very simple file must contains at least a feed URL, for example:

```
<?php
return [
    'url' => 'https://example.org/rss.xml',
];
```

In this case, the original feed will be retrieved and used to generate a feed with complete items' content.

The name of the file (without the `.php` extension) must be the feed name in the query string. E.g. a file named `example-feed.php` will be used with the URL `http://<feedeliser-domain>/?example-feed`.

### Complete configuration options

***TODO***

## Web server configuration

A dedicated domain is prefered, only the `public` and `web` directory must be accessible. To be sure your feeds are not used by anybody, HTTP Authentication can be used.

Sample configurations for Nginx and Apache are given below (PHP, SSL and Authentication configurations not included).

### Nginx

```
server {
    listen 80;
    listen [::]:80;
    server_name feedeliser;
    root /var/www/html/feedeliser/web;
    location = / {
        fastcgi_param SCRIPT_FILENAME /var/www/html/feedeliser/web/index.php;
    }
    location ~ /public/ {
        root /var/www/html/feedeliser;
    }
}
```

### Apache

```
<VirtualHost *:80>
    ServerName feedeliser
    DocumentRoot "/var/www/html/feedeliser/web"
    <Directory "/var/www/html/feedeliser/web">
        Require all granted
    </Directory>
    Alias "/public" "/var/www/html/feedeliser/public"
    <Directory "/var/www/html/feedeliser/public">
        Require all granted
    </Directory>
</VirtualHost>
```

## Other files

- `datas/curl_cookies` store cookies used by cURL HTTP queries
- `datas/log` is created by Monolog and contains all events (debug level), setting up log rotation is recommended
