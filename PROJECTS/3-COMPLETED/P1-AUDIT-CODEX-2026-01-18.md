# Hypercart Helper Security & Performance Red-Flag Audit

Date: 2026-01-18
Scope: Targeted review of security and performance red flags in the current codebase.
Status: Mostly Completed

## Findings (ranked by Severity ➜ Priority)

### 1) Web-accessible log directory may expose sensitive data
- **Category:** Security
- **Priority:** **P2**
- **Why this matters:** Log files are stored under `WP_CONTENT_DIR` and rely on `.htaccess` + `index.php` for access control. On Nginx/IIS (or misconfigured Apache), `.htaccess` is ignored, leaving logs potentially publicly accessible under `/wp-content/hypercart-logs/`. Logs can include operational context and user identifiers, creating data exposure risk.
- **Evidence:** Log directory location and protection rely on `.htaccess` and `index.php` creation in `get_log_dir()`.
- **DEFERRED TODOs:**
  - [ ] Move logs outside the web root by default (e.g., `WP_CONTENT_DIR`’s parent) and keep the `hypercart_log_dir` filter as an override.
  - [ ] Add optional web server config guidance (Nginx/IIS) to ensure log directory is denied from web access. Added Notes

**Developer Warning**
- [x] Added warning notices for Developers in Self Test page and included new README-SECURITY.md file. Any sort of logging has inherent security risks but trade offs for data/system monitoring are needed.


### 2) Log context is written without redaction or allowlist
- **Category:** Security
- **Severity:** **Medium**
- **Priority:** **P2**
- **Why this matters:** Context arrays are JSON-encoded and written verbatim to log files. If upstream plugins pass secrets (tokens, API keys, emails), those values are stored in plaintext, increasing exposure impact if logs are leaked.
- **Evidence:** `log()` appends `wp_json_encode( $context )` directly to the log line without filtering.
- **Actionable TODOs:**
  - [x] Added v1.0 context sanitizer/redactor (e.g., redact keys like `password`, `token`, `secret`, `authorization`, `api_key`) and test fixture.

### 3) Reading log snippets loads entire log file into memory
- **Category:** Performance
- **Severity:** **Medium**
- **Priority:** **P2**
- **Why this matters:** `read_log()` calls `file()` to load the entire log into memory even when only the last N lines are requested. Large log files can cause memory spikes and slow admin requests.
- **Evidence:** `read_log()` uses `file()` and `array_slice()` to return subsets.
- **Actionable TODOs:**
  - [x] Implement a tail-style reader that reads from the end of the file for negative `$lines`.
  - [x] Add a maximum file size or line-count guard for admin reads.

### 4) Log file listing scans the entire directory without caching
- **Category:** Performance
- **Severity:** **Low**
- **Priority:** **P3**
- **Why this matters:** `get_log_files()` uses `glob()` and `filesize()` on every call. In environments with many log files, this can be slow for admin pages.
- **Evidence:** `get_log_files()` iterates through all log files and computes sizes each time.
- **Actionable TODOs:**
  - [x] Cache the log file list via transients for short durations in admin contexts.
  - [x] Lazy-loading sizes only when needed (e.g., on details view).
