# Neochrome WP Toolkit - Quick Start

**Version:** 1.0.41
© Copyright 2025 Neochrome, Inc.

> **For AI Assistants:** Read this file first to understand available tools.

---

## What's Included

### Core Tools

| File | Purpose |
|------|---------|
| `bin/check-performance.sh` | Main script - scans PHP for performance antipatterns |
| `tests/fixtures/ajax-antipatterns.php` | REST/wp_ajax antipatterns (missing pagination/nonces) |
| `tests/fixtures/ajax-antipatterns.js` | AJAX polling antipatterns (setInterval storms) |
| `tests/fixtures/antipatterns.php` | Examples of bad patterns (for testing/reference) |
| `tests/fixtures/clean-code.php` | Examples of correct patterns |
| `tests/run-fixture-tests.sh` | Test runner for fixture validation |

### Integration & Security Tools

| File | Purpose | Location |
|------|---------|----------|
| `setup-integration-security.sh` | 🔒 Setup credential protection (run BEFORE integrations) | Repository root |
| `.env.example` | Template for local integration credentials | Repository root |
| `bin/post-to-slack.sh` | 📢 Post audit results to Slack webhook | `dist/bin/` |
| `bin/format-slack-message.sh` | 🎨 Format JSON results as Slack Block Kit | `dist/bin/` |
| `bin/test-slack-integration.sh` | 🧪 Test Slack integration with mock data | `dist/bin/` |
| `bin/pre-commit-credential-check.sh` | Pre-commit hook to prevent credential leaks | `dist/bin/` |
| `bin/.gitignore-integrations-template` | Reference template for .gitignore patterns | `dist/bin/` |

> **🔒 Setting up Slack/Discord/GitHub integrations?** Run `./setup-integration-security.sh` first to protect your repository from accidental credential commits. See [PROJECT/SECURITY-README.md](../PROJECT/SECURITY-README.md)

---

## Quick Start

### Option A: Using Composer (Recommended)

If your project has the toolkit's `composer.json` or you've added the scripts to your own:

```bash
# Run performance audit
composer audit

# Run in strict mode (fails on warnings - for CI)
composer audit:strict

# Run full CI pipeline (audit + tests)
composer ci

# Verbose output (show all matches)
composer audit:verbose

# Scan specific directory
composer audit:src
```

### Option B: Direct Script Execution

```bash
# From project root (adjust path as needed)
./dist/bin/check-performance.sh --paths "."

# Scan specific folders
./dist/bin/check-performance.sh --paths "includes/ src/"

# Verbose output (show all matches)
./dist/bin/check-performance.sh --paths "." --verbose

# Strict mode (fail on warnings too)
./dist/bin/check-performance.sh --paths "." --strict

# Without log file
./dist/bin/check-performance.sh --paths "." --no-log
```

---

## Composer Scripts Reference

Add these to your project's `composer.json` for easy access:

```json
{
  "scripts": {
    "audit": "./dist/bin/check-performance.sh --paths '.'",
    "audit:verbose": "./dist/bin/check-performance.sh --paths '.' --verbose",
    "audit:strict": "./dist/bin/check-performance.sh --paths '.' --strict",
    "audit:src": "./dist/bin/check-performance.sh --paths 'src/'",
    "test": "./dist/tests/run-fixture-tests.sh",
    "ci": ["@audit:strict", "@test"]
  }
}
```

| Script | Description |
|--------|-------------|
| `composer audit` | Scan entire project for antipatterns |
| `composer audit:strict` | Strict mode - fail on warnings (for CI/CD) |
| `composer audit:verbose` | Show all matches, not just first occurrence |
| `composer audit:src` | Scan only `src/` directory |
| `composer test` | Run fixture validation tests |
| `composer ci` | Full CI pipeline: strict audit + tests |

---

## CI/CD Integration

### GitHub Actions

Add to your workflow (`.github/workflows/quality.yml`):

```yaml
name: Code Quality

on: [push, pull_request]

jobs:
  performance-audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run Performance Audit
        run: composer ci
        # Or without Composer:
        # run: ./dist/bin/check-performance.sh --paths '.' --strict
```

### GitLab CI

```yaml
performance-audit:
  script:
    - composer ci
  only:
    - merge_requests
    - main
```

---

## Review Results

The script outputs:
- **ERRORS** `[CRITICAL]` `[HIGH]` - Must fix, will fail CI
- **WARNINGS** `[MEDIUM]` `[LOW]` - Review recommended

### Exit Codes

| Code | Meaning |
|------|---------|
| `0` | All checks passed |
| `1` | Errors found (critical issues) |
| `1` | Warnings found (when `--strict` mode) |

---

## Logging

Logs are written to `dist/logs/` by default:
- **Filename format:** `YYYY-MM-DD-HHMMSS-UTC.log`
- **Disable logging:** Use `--no-log` flag

Add to `.gitignore`:
```bash
echo "dist/logs/" >> .gitignore
```

---

## JSON Output & Baseline Files (for tooling/CI)

### JSON output

Use JSON when integrating with CI, IDEs, or other tooling:

```bash
./dist/bin/check-performance.sh --paths "." --format json --no-log
```

The JSON payload includes:
- `version` – script version (e.g. `"1.0.41"`)
- `timestamp` – ISO8601 UTC timestamp
- `paths_scanned` and `strict_mode`
- `summary` – `total_errors`, `total_warnings`, `baselined`, `stale_baseline`, `exit_code`
- `findings[]` – individual rule hits
- `checks[]` – per-rule pass/fail with impact and count

Example (trimmed) JSON output:

```json
{
  "version": "1.0.41",
  "summary": {
    "total_errors": 2,
    "total_warnings": 1,
    "baselined": 3,
    "stale_baseline": 1,
    "exit_code": 1
  },
  "findings": [
    {
      "id": "unbounded-posts-per-page",
      "severity": "error",
      "file": "./wp-content/themes/example/functions.php",
      "line": 42,
      "message": "Unbounded posts_per_page (-1) can cause memory exhaustion."
    }
  ]
}
```

### Baseline files

Baselines let you "grandfather in" existing findings while still failing CI on **new or increased** issues.

- Generate a baseline from the current codebase:

  ```bash
  ./dist/bin/check-performance.sh --paths "." --format json --generate-baseline
  ```

  This writes a `.neochrome-baseline` file with per-rule, per-file allowed counts.

- Use a specific baseline file:

  ```bash
  ./dist/bin/check-performance.sh --paths "." --format json --baseline .neochrome-baseline
  ```

- Temporarily ignore an existing baseline (e.g. local debugging):

  ```bash
  ./dist/bin/check-performance.sh --paths "." --format json --ignore-baseline
  ```

In JSON `summary`:

- `baselined` reports how many findings were **suppressed** by the baseline.
- `stale_baseline` counts baseline entries where the recorded allowance is **higher** than current matches (these can usually be reduced).

Baseline paths are normalized (leading `./` is stripped) so a `.neochrome-baseline` generated on macOS matches runtime findings on Linux/GitHub Actions and keeps `baselined`/`stale_baseline` counts consistent across environments.

---

## What It Detects

### Critical Errors (Build Fails)

| Pattern | Risk |
|---------|------|
| AJAX polling via `setInterval` + fetch/ajax | Request storms hammer backend |
| `register_rest_route` without pagination limit | Unbounded REST data fetch |
| `wp_ajax_*` handlers missing nonce validation | Unlimited AJAX flood/no cache |
| `posts_per_page => -1` | Memory exhaustion |
| `numberposts => -1` | Memory exhaustion |
| `nopaging => true` | Disables all limits |
| `wc_get_orders(['limit' => -1])` | WooCommerce memory crash |
| `get_terms()` without `number` | Term table explosion |
| `pre_get_posts` forcing unbounded | Silent performance killer |
| Unbounded SQL on `wp_terms` | Full table scans |

### Warnings (Review Recommended)

| Pattern | Risk | Impact |
|---------|------|--------|
| `ORDER BY RAND()` | Full table scan | `[HIGH]` |
| `set_transient()` without expiration | Stale data, bloated DB | `[MEDIUM]` |
| N+1 patterns (meta in loops) | Query multiplication | `[MEDIUM]` |
| `current_time('timestamp')` | Timezone issues | `[LOW]` |

---

## Suppressing False Positives

Add `phpcs:ignore` comment on the line before:

```php
// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Intentional for display
$time = current_time( 'timestamp' );
```

---

## Example Output

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Neochrome WP Toolkit - Performance Checker v1.0.41
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

▸ Unbounded posts_per_page [CRITICAL]
  ✓ Passed

▸ Unbounded numberposts [CRITICAL]
  ✓ Passed

▸ Timezone-sensitive patterns [LOW]
  ⚠ WARNING (2 occurrence(s) without phpcs:ignore)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  SUMMARY
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Errors:   0
  Warnings: 1

✓ All critical checks passed
```

---

## Integrations

### Slack Notifications

Send real-time alerts when performance checks fail in CI:

```bash
# 1. Setup credential protection (FIRST!)
./setup-integration-security.sh

# 2. Test the integration (no credentials needed)
./dist/bin/test-slack-integration.sh

# 3. Configure credentials for real testing
cp .env.example .env
nano .env  # Add SLACK_WEBHOOK_URL

# 4. Test with real webhook
export SLACK_WEBHOOK_URL="https://hooks.slack.com/services/YOUR/WEBHOOK"
./dist/bin/test-slack-integration.sh

# 5. Use in your scripts
./dist/bin/check-performance.sh --format json > results.json
./dist/bin/post-to-slack.sh results.json
```

**GitHub Actions Setup:**
1. Create Slack webhook at https://api.slack.com/apps
2. Add to GitHub Secrets as `SLACK_WEBHOOK_URL`
3. Use provided workflow: `.github/workflows/performance-audit-slack.yml`

**Available Workflows:**
- `performance-audit-slack.yml` - Notify on all runs
- `performance-audit-slack-on-failure.yml` - Notify only on failures

See [PROJECT/DETAILS/INTEGRATIONS.md](../PROJECT/DETAILS/INTEGRATIONS.md) for complete guide.

---

## For AI Assistants

**Recommended workflow:**

1. Run `composer audit:verbose` (or `./dist/bin/check-performance.sh --paths "." --verbose`)
2. If errors found, fix them before proceeding
3. For warnings, evaluate if they're intentional or need fixing
4. Create an audit report in `AUDITS/` folder with findings

---

## Links

- **Repository:** https://github.com/NeochromeTeam/neochrome-toolkit-automated-wp-code-testing
- **Issues:** https://github.com/NeochromeTeam/neochrome-toolkit-automated-wp-code-testing/issues
- **Contact:** noel@neochro.me
