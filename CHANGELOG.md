# Changelog

All notable changes to the Hypercart Helper plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.11] - 2026-01-19

### Changed
- Enhanced Chart Helper self-test with extensive debugging information to diagnose file detection issues
  - Added HYPERCART_HELPER_DIR validation (existence, readability)
  - Added assets directory structure validation (assets/, vendor/, chartjs/, js/)
  - Added detailed file path debugging for both ChartJS and wrapper files
  - Added directory file listing (shows actual files present in chartjs/ and js/ directories)
  - Added symlink detection for asset files
  - Added file permissions display (octal format)
  - Added file size reporting for existing files
  - New "Debug Info" section in self-test output with comprehensive diagnostics

### Technical Details
- Self-test now reports full absolute paths for all asset files
- Directory scanning shows actual files present vs. expected files
- Helps diagnose issues where files exist on server but aren't detected by `file_exists()`
- Useful for debugging permission issues, symlink problems, or path mismatches
- All debug output added to existing "Chart Helper" test section

## [1.1.10] - 2026-01-18

### Fixed
- Self Test redaction fixture now searches robustly for the latest redacted entry

## [1.1.9] - 2026-01-18

### Added
- Self Test now validates redaction of a blacklisted context key

## [1.1.8] - 2026-01-18

### Added
- Context redaction for known sensitive keys with a redaction source marker

## [1.1.7] - 2026-01-18

### Changed
- `get_log_files()` supports optional size skipping and short admin caching
- Added filters to tune log file list cache TTL and size inclusion

## [1.1.6] - 2026-01-18

### Changed
- `read_log()` now uses a tail-style reader for negative line counts
- Admin log reads enforce max line and max size guards via filters

## [1.1.5] - 2026-01-18

### Added
- Ignore `dist/` in `.gitignore` to keep toolkit artifacts out of version control

### Changed
- Changelog corrected to reflect actual tab icon support and PHPDoc notes

## [1.1.5] - 2026-01-18

### Fixed
- **Security Guide Page** - Implemented missing `render_security_guide_page()` method that renders README-SECURITY.md via the Markdown Viewer
- Security warning link on settings page now properly displays the security guide without errors

## [1.1.4] - 2026-01-18

### Added
- **Markdown Viewer Helper** (`Hypercart_Markdown_Viewer`) - Safe Markdown-to-HTML renderer with:
  - Shortcode support: `[hypercart_markdown file="..." title="..."]`
  - PHP API: `render_markdown()` and `render_file()` methods
  - Extensible hooks/filters for customization
  - File security with allowlist-based access control
  - Transient caching support for performance
- **Tabbed Settings UI** (`Hypercart_Admin_Tabs`) - Reusable tabbed navigation component with:
  - Dashicons icon support
  - CSS variable-based color customization
  - Settings, Self Test, Demo, and Changelog tabs
- **Changelog Tab** - Now renders `CHANGELOG.md` using the Markdown Viewer for improved readability
- **Security Enhancements**:
  - Log directory security check (`is_log_dir_insecure()`) detects if logs are in web-accessible locations
  - Security warning displayed on settings page when logs are at risk
  - Security status included in Self Test results

### Changed
- Admin settings page now uses tabbed interface for better organization
- Self Test results now include security status for log directory
- Plugin action link now points to Self Test tab

### Technical Details
- Markdown Viewer supports: headings, paragraphs, emphasis, links, lists, blockquotes, code blocks
- All output sanitized via `wp_kses()` with configurable allowed tags
- Tab helper uses WordPress core `.nav-tab` classes with custom styling
- Security check uses `realpath()` for accurate path comparison
- Markdown Viewer provides 5 extensibility hooks for consuming plugins

## [1.0.5] - 2026-01-01

### Added
- Extended `Hypercart_Time` class with four new session/duration management methods:
  - `parse_mysql_utc()` - Parse MySQL DATETIME strings (stored in UTC) back to Unix timestamps
  - `duration_seconds()` - Calculate session duration with pause period support
  - `format_duration()` - Format durations in human-readable format ('2h 30m' or '2.5 hours')
  - `validate_session_times()` - Validate chronological order of session start/end and pause periods

### Technical Details
- `parse_mysql_utc()` converts MySQL DATETIME strings to Unix timestamps, complementing existing `mysql_utc()` method
- `duration_seconds()` supports optional pause periods array with automatic boundary validation
- `format_duration()` supports two formats: 'short' (2h 30m) and 'decimal' (2.5 hours) with i18n support
- `validate_session_times()` returns validation result array with boolean 'valid' flag and detailed error messages
- All new methods include comprehensive PHPDoc documentation
- Pause period validation ensures pauses fall within session boundaries
- Duration calculation handles ongoing sessions (null end_time) and active pauses (null pause end)

## [1.0.4] - 2024-12-30

### Changed
- Updated `.gitignore` to exclude entire `/dist` folder from repository
- Prevents Neochrome WP Toolkit files from being tracked in version control
- Added `test-results.json` to ignored artifacts

### Technical Details
- `.gitignore` now ignores `dist/` instead of just `dist/logs/`
- Toolkit remains available for local testing but won't be committed
- Cleaner repository without third-party testing tools in version control

## [1.0.3] - 2024-12-29

### Added
- GitHub Actions workflows for automated testing and quality checks
  - **code-quality.yml** - Runs on commits and PRs (performance audit, fixture tests, PHP syntax)
  - **scheduled-audit.yml** - Daily comprehensive audits with automatic issue creation
  - **pr-checks.yml** - PR-specific validations (size, conflicts, sensitive data, auto-labeling)
- Concurrency controls to prevent redundant workflow runs
- Multi-PHP version testing (PHP 7.4, 8.0, 8.1, 8.2, 8.3)
- Automated PR comments with quality check results
- Artifact uploads for failed audits (30-day retention)
- Auto-labeling based on changed files (php, javascript, css, tests, documentation, ci/cd)

### CI/CD Features
- **Concurrency Groups:** Prevents multiple runs for same PR/branch
- **Cancel-in-progress:** Automatically cancels outdated workflow runs
- **Draft PR Detection:** Skips checks for draft PRs to save resources
- **Sensitive Data Scanning:** Checks for hardcoded passwords/API keys
- **CHANGELOG Validation:** Reminds to update CHANGELOG on PRs
- **PR Size Analysis:** Categorizes PRs as small/medium/large
- **Automatic Issue Creation:** Creates issues for scheduled audit failures

### Technical Details
- Workflows use `actions/checkout@v4` for code checkout
- PHP setup via `shivammathur/setup-php@v2`
- Artifact management via `actions/upload-artifact@v4`
- GitHub Script integration via `actions/github-script@v7`
- Strict mode enabled for performance audits in CI
- JSON report generation for scheduled audits

## [1.0.2] - 2024-12-29

### Added
- "Self Test" action link on All Plugins page for quick access to diagnostics
- Version number in settings page title (e.g., "Hypercart Helper Settings v1.0.2")

### Changed
- Settings page title now includes version number for better visibility
- Plugin action links now prioritize Self Test link (appears first)

### Technical Details
- Added `add_plugin_action_links()` method to `Hypercart_Admin` class
- Modified `add_admin_menu()` to include version in page title using `sprintf()`
- Added filter hook: `plugin_action_links_{basename}` for custom action links

## [1.0.1] - 2024-12-29

### Added
- Settings page under Settings → Hypercart Helper in WordPress admin
- Comprehensive Self Test functionality with three test suites:
  1. **Plugin Detection Test** - Verifies all classes and constants are loaded
  2. **Time Handling Test** - Tests all Hypercart_Time methods including UTC/local formatting, ISO 8601, weekly slots, and mock time
  3. **Log Handling Test** - Tests log directory creation, file writing/reading, security files, and all log levels
- Clear pass/fail messaging with visual indicators (green for pass, red for fail)
- Detailed debugging information for each test including:
  - Specific values returned by each function
  - File paths and permissions
  - Version information
  - Error messages with context when tests fail
- Admin interface with custom styling for test results
- Security file verification (.htaccess and index.php in log directory)
- `.gitignore` file to exclude log files and temporary files from repository

### Technical Details
- New file: `includes/class-hypercart-admin.php` - Admin interface class
- New file: `.gitignore` - Git exclusion rules for logs and temporary files
- Admin menu integration under Settings
- Nonce verification for self-test form submission
- Transient-based result storage for redirect pattern
- Inline CSS for admin styling
- Exception handling with detailed error reporting

### Repository
- Excluded `dist/logs/` from version control
- Excluded `hypercart-logs/` from version control
- Excluded OS files (.DS_Store, Thumbs.db)
- Excluded IDE files (.vscode/, .idea/)
- Excluded environment files (.env)

## [1.0.0] - 2024-12-29

### Added
- Initial release of Hypercart Helper plugin
- `Hypercart_Time` class for centralized UTC-based time management
  - Store UTC, display local timezone philosophy
  - Methods for formatting, parsing, and manipulating timestamps
  - Weekly slot calculation (0-167) for baseline tracking
  - Mock time support for deterministic testing
  - Full timezone conversion support
- `Hypercart_Logger` class for structured file-based logging
  - UTC-timestamped log entries
  - Daily log file rotation (hypercart-YYYY-MM-DD.log format)
  - Four log levels: DEBUG, INFO, WARNING, ERROR
  - Automatic log cleanup via daily cron job
  - Plugin context in every log entry
  - Structured context data support via JSON
  - Security: .htaccess and index.php protection for log directory
- Activation hook to schedule daily log cleanup cron
- Deactivation hook to clear scheduled events
- Uninstall script to remove log files and directory
- Complete inline documentation following WordPress coding standards

### Technical Details
- Requires WordPress 6.0+
- Requires PHP 7.4+
- No database tables (file-based logging only)
- Stateless utility classes for easy extraction and reuse
- PSR-3 inspired log levels
- Full i18n support with 'hypercart-helper' text domain

### Files Created
- `hypercart-helper.php` - Main plugin file
- `includes/class-hypercart-time.php` - Time utility class
- `includes/class-hypercart-logger.php` - Logger utility class
- `uninstall.php` - Cleanup on plugin deletion
- `README.md` - Complete specification and usage documentation
- `CHANGELOG.md` - Version history

[1.0.0]: https://github.com/neochrome/hypercart-helper/releases/tag/v1.0.0
