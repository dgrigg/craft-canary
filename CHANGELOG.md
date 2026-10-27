# Release Notes for Site Canary

## 1.0.3 - 2026-10-27

- Scan notices and "already running" messages now use the Site Canary name.

## 1.0.2 - 2026-10-27

- Updated `LICENSE.md` to the current Craft License text.

## 1.0.1 - 2026-10-27

- Renamed the plugin to Site Canary, with the handle `site-canary`. Console commands are now `php craft site-canary/scan`, the config file is `config/site-canary.php`, and the utility is under Utilities → Site Canary.

## 1.0.0 - 2026-10-27

- Initial release.
- File integrity checks: SHA-256 baseline of PHP and Twig files, reporting added, modified and deleted files.
- System health checks: temp-directory processes and executables, CPU, mining pool connections, Redis binding, crontab entries.
- `site-canary/scan` console command with `--baseline` and `--dry-run`.
- HTML and plain-text email report, including an optional daily "all clear".
- Utilities → Site Canary: run a scan from the control panel, see the results and email the report, or rebuild the baseline.
- Control panel settings, with `config/site-canary.php` overrides.
