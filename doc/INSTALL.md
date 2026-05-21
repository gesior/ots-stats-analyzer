# Installation on Ubuntu 22.04

This application is a CLI tool for importing OTS Statistics logs into SQLite, plus a short-lived browser UI for analysis. **You do not need nginx or Apache** — PHP from the command line and the built-in `php -S` server are enough.

Converting .log files to SQLite (`import` script) uses a lot of SSD and up to 2 GB RAM. **I do not recommend running it on the same server on which OTS is running.**

I tested it on the cheapest Hetzner host (2 vCores, 4 GB RAM, 40 GB SSD) and imported there 6 months of OTS Stats logs from popular OTS, which has 11 GB of data.
I used default config, which is 'skip data older than 30 days'. It took 9 minutes to import logs from 30 days. Generated SQLite database size is 5.2 GB - there are many indexes and aggregations to speed up the website UI.


## Requirements

- Ubuntu 22.04+
- A normal user account with `sudo` (only package install and global Composer need root; everything else runs as your user)
- A directory with OTS server log files (optional during installation)

## 1. PHP and extensions

Install the CLI interpreter and SQLite support (PDO):

```bash
sudo apt update
sudo apt install -y git php-cli php-dom php-mbstring php-sqlite3 screen screenie zip
```

## 2. Composer

Download the official installer and install Composer globally:

```bash
cd /tmp
curl -sS https://getcomposer.org/installer -o composer-setup.php
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

## 3. Application code

Clone the repository (or copy an archive) and enter the project directory:

```bash
git clone https://github.com/gesior/ots-stats-analyzer.git
cd ots-stats-analyzer
```

Adjust the path if you use a different source or directory.

## 4. PHP dependencies (without dev packages)

Install production dependencies only (no PHPUnit or other `require-dev` packages):

```bash
composer install --no-dev --optimize-autoloader
```

## 5. Data setup

Create directories for logs and the database (if they do not exist):

```bash
mkdir -p data var
```

Copy log files from your OTS server into `data/` (flat layout, no subdirectories), for example:

- `dispatcher.log`, `dispatcher_slow.log`, `dispatcher_very_slow.log`
- `lua.log`, `lua_slow.log`, `lua_very_slow.log`
- `sql.log`, `sql_slow.log`, `sql_very_slow.log`
- `special.log`, `special_slow.log`, `special_very_slow.log`

## 6. Log import (CLI)

First import (last 30 days by default — suitable for multi-month log dumps):

```bash
php bin/import.php import --data-dir=data --db=var/ots-stats.sqlite
```

Daily log files (separate files per day) can live in `data/` next to the standard names, for example:

- `dispatcher_2026-05-19.log`, `dispatcher_2026-05-20.log`
- `dispatcher_slow_2026-05-20.log`

Each file is imported independently. If a file at the same path is replaced (different first line), the importer loads the whole new file instead of resuming from a previous offset.

Full historical import (no date limit):

```bash
php bin/import.php import --days=0 --data-dir=data --db=var/ots-stats.sqlite
```

Database and import status:

```bash
php bin/import.php status
```

An interrupted import can be resumed — run the same `import` command again.

## 7. Browser UI (`php -S`)

A permanent web server is not required. After import, start PHP’s built-in server only while you analyze data:

```bash
php -S 0.0.0.0:8080 -t public public/router.php
```

- Locally (home PC): [http://127.0.0.1:8080](http://127.0.0.1:8080)
- From another machine on the network (running on VPS): `http://<server-ip>:8080` (ensure the firewall allows port 8080)

Stop the server with `Ctrl+C` in the terminal where it is running.

Override the database path (default: `var/ots-stats.sqlite`):

```bash
export OTS_DB_PATH=/path/to/ots-stats.sqlite
php -S 0.0.0.0:8080 -t public public/router.php
```

## Notes

- **Tests** (`vendor/bin/phpunit`) require `composer install` **without** `--no-dev` — usually not needed on a production or analysis host.
- The `var/` directory must be writable by the user running the import.
