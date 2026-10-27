# Release Notes for Canary

## 1.0.0 - 2026-10-27

- Initial release.
- File integrity checks: SHA-256 baseline of PHP and Twig files, reporting added, modified and deleted files.
- System health checks: temp-directory processes and executables, CPU, mining pool connections, Redis binding, crontab entries.
- `canary/scan` console command with `--baseline` and `--dry-run`.
- HTML and plain-text email report, including an optional daily "all clear".
- Utilities → Canary: run a scan from the control panel, see the results and email the report, or rebuild the baseline.
- Control panel settings, with `config/canary.php` overrides.
