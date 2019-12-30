# Feedeliser
Transform a web page into an RSS feed, or make a complete feed from a truncated one

## SQLite cache
```CREATE TABLE IF NOT EXISTS feed_entry (feed TEXT, url TEXT, content TEXT, title TEXT, last_access INTEGER);```
```CREATE TABLE IF NOT EXISTS podcast_entry (feed TEXT, url TEXT, enclosure TEXT, length INTEGER, type TEXT, duration INTEGER);```
```CREATE TABLE IF NOT EXISTS image (feed TEXT, type TEXT, id TEXT, file TEXT);```
