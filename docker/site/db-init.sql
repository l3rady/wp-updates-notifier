-- Local site only (docker-compose.yml): the multisite's database, created when the site-db volume is new.
-- `make site-setup` creates it on an existing volume.
CREATE DATABASE IF NOT EXISTS wordpress_ms;
