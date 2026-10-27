# Canary for Craft CMS

Daily file integrity and server health checks for Craft CMS 5, with an emailed report.

Canary hashes your project's PHP and Twig files after each deployment, then checks them every day. Any file that was added, modified or deleted since the baseline is reported, along with server-level signs of compromise: processes and executables in temp directories, runaway CPU, connections to crypto-mining pools, Redis exposed beyond localhost, and unexpected crontab entries.

By default a report goes out every day, including an "all clear" when nothing is wrong. If the email stops arriving, the scan has stopped running.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- Linux server for the system health checks (file integrity works anywhere)

## Installation

```bash
composer require dgrigg/craft-canary
php craft plugin/install canary
```

Add the report recipient to `.env` (comma-separate multiple addresses):

```
CANARY_ALERT_EMAIL=you@example.com
```

## Usage

```bash
php craft canary/scan --baseline   # Generate a new baseline (run after deployments)
php craft canary/scan              # Run the check and email the report
php craft canary/scan --dry-run    # Show results without sending email
```

The command exits non-zero when issues are found, so it can also gate a CI step or a monitoring check.

You can also run a scan from the control panel under **Utilities → Canary**. It shows the results on the page and emails the report, even when the daily "all clear" is turned off. Access is controlled by the **Utilities → Canary** user permission. The utility can also rebuild the baseline. That asks for the user's password first and is logged with their username, because a rebuild accepts every file as it currently is.

### 1. Rebuild the baseline on every deploy

Add this as the last step of your deploy script or CI pipeline, after `composer install` and `php craft up`:

```bash
php craft canary/scan --baseline
```

Without this, every legitimate deploy shows up as modified files in the next report.

### 2. Schedule the daily scan

Craft has no built-in scheduler, so add a cron entry on the server. Edit the crontab of the user that owns the site files (usually the user your deploys run as, not root):

```bash
crontab -e
```

Then add a line that runs the scan once a day:

```
# Canary: daily integrity and health check at 6:00 server time
0 6 * * * cd /var/www/example.com && /usr/bin/php craft canary/scan > /dev/null 2>&1
```

Adjust it for your server:

- **Project path:** the directory that contains the `craft` executable. With atomic or zero-downtime deploys, use the symlink that points at the live release (e.g. `/var/www/example.com/current`), not a release folder.
- **PHP binary:** cron runs with a minimal `PATH`, so use the full path from `which php`. If the server has several PHP versions, pick the one the site runs on (e.g. `/usr/bin/php8.3`).
- **Schedule:** `0 6 * * *` is 6:00 every day in the server's timezone. Pick a time when someone will see the email.
- **Output:** Canary emails the report and writes its own log, so cron's output is discarded. Leave off `> /dev/null 2>&1` if you also want cron to mail the output to the account's `MAILTO`.

To confirm the entry works, run the same command by hand as that user with `--dry-run`:

```bash
cd /var/www/example.com && /usr/bin/php craft canary/scan --dry-run
```

Runs are locked with Craft's mutex, so overlapping runs are skipped. The crontab check (below) inspects this same user's crontab by default, and the scan's own entry is allowed because it contains `craft`.

## What gets checked

### File integrity

SHA-256 of every file matching the extensions (`php`, `twig`) under the scan paths, compared against the baseline in `storage/canary/baseline.json`:

| Default scan path | Why |
|---|---|
| `bootstrap.php`, `craft` | Entry points (hashed whatever their extension) |
| `config` | Config files, including `general.php` and `db.php` |
| `modules` | Custom module code |
| `templates` | Twig templates |
| `web` | `index.php` and anything dropped into public folders, such as a PHP shell in an uploads volume |

`vendor` and `storage` are not scanned. To also watch Craft core, add `vendor/craftcms/cms/src` to the scan paths; the deploy-time baseline keeps updates from being reported.

### System health

| Check | Alert when |
|---|---|
| Temp processes | A running process's binary is in `/tmp`, `/var/tmp` or `/dev/shm` |
| Temp executables | An executable file sits at the top level of those directories |
| CPU | Any process is above the CPU threshold (default 80%) |
| Mining connections | An outbound connection's remote port is a known mining pool port, and the local port isn't 80/443 |
| Redis | `redis-cli config get bind` doesn't include `127.0.0.1` |
| Crontab | The cron user's crontab (default: the user running the scan) has a line matching none of the allowed patterns, or exists but can't be read |

Checks whose tools aren't installed (`ss`, `redis-cli`, `crontab`) are skipped quietly. Turn the whole group off with `enableSystemChecks` for shared hosting or non-Linux servers.

The crontab check reads the crontab of the system account the scan runs as, or of `cronUser` when one is set (which usually requires root). If that crontab exists but can't be read, the scan reports it as an issue rather than passing. A scan run from **Utilities → Canary** runs as the web server's PHP user, which is often a different account from the one cron uses. If that makes the check unreliable on your server, turn it off with `enableCrontabCheck` and keep the other system checks.

## Configuration

Settings are under **Settings → Plugins → Canary**. To manage them in code, copy `vendor/dgrigg/craft-canary/src/config.php` to `config/canary.php`; values there override the control panel and can vary by environment:

```php
return [
    '*' => [
        'alertEmail' => '$CANARY_ALERT_EMAIL',
        'excludePaths' => ['node_modules', '.git', 'web/cpresources'],
    ],
    'dev' => [
        'enableSystemChecks' => false,
    ],
];
```

| Setting | Default | |
|---|---|---|
| `alertEmail` | `$CANARY_ALERT_EMAIL` | Comma-separated recipients; env vars allowed |
| `sendAllClear` | `true` | Email clean runs too |
| `scanPaths` | see above | Directories and files, relative to the project root |
| `fileExtensions` | `['php', 'twig']` | Extensions hashed inside directories |
| `excludePaths` | `['node_modules', '.git']` | A plain name matches any path segment; a name with a slash matches a path prefix |
| `enableSystemChecks` | `true` | Run the server-level checks |
| `cpuThreshold` | `80` | CPU percentage that triggers an alert |
| `miningPorts` | `[3333, 4444, 10300, 14444, 45700]` | Remote ports treated as mining pools |
| `enableCrontabCheck` | `true` | Run the crontab check |
| `cronUser` | `''` | Whose crontab to inspect; empty for the user running the scan. Inspecting another user's crontab usually requires root |
| `allowedCronPatterns` | `['craft', 'artisan', 'composer', 'apt-get']` | Crontab lines containing any of these are expected |

## Logging

Everything Canary logs goes to `storage/logs/canary-YYYY-MM-DD.log`, including the full list of issues when a check fails.

## Limitations

- Canary detects changes after the fact; it doesn't block them. Pair it with a WAF and keeping Craft and plugins updated.
- The baseline lives on the same server it protects. An attacker with write access could regenerate it, though the next report would then show an unexpected baseline date.
