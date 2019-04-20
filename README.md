# Feedeliser
Transform a web page into an RSS feed, or make a complete feed from a truncated one

## SQLite cache
```CREATE TABLE IF NOT EXISTS feed_entry (feed TEXT, url TEXT, content TEXT, title TEXT, last_access INTEGER)```
