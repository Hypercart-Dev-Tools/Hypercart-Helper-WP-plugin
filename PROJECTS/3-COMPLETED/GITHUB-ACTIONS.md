# GitHub Actions - Automated Testing & Quality Checks

**Version:** 1.0.3  
**Date:** December 29, 2024

This document describes the GitHub Actions workflows configured for the Hypercart Helper plugin.

---

## Overview

Three automated workflows ensure code quality and catch issues early:

1. **Code Quality & Performance Audit** - Runs on every commit and PR
2. **Scheduled Performance Audit** - Daily comprehensive checks
3. **Pull Request Checks** - PR-specific validations

---

## Workflow 1: Code Quality & Performance Audit

**File:** `.github/workflows/code-quality.yml`

### Triggers
- **Push** to branches: `main`, `develop`, `feature/**`, `hotfix/**`
- **Pull Requests** to: `main`, `develop`
- **PR Events:** opened, synchronize, reopened

### Concurrency Control
```yaml
concurrency:
  group: ${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}
  cancel-in-progress: true
```

**What it does:**
- Cancels in-progress runs when new commits are pushed
- Prevents redundant workflow runs
- Saves CI/CD resources and time

### Jobs

#### 1. Performance Audit
- Runs Neochrome WP Toolkit in **strict mode**
- Scans entire codebase for antipatterns
- Fails build on any errors or warnings
- Uploads logs on failure (7-day retention)

```bash
./dist/bin/check-performance.sh --paths "." --strict --no-log
```

#### 2. Fixture Tests
- Validates toolkit detection patterns
- Runs all 7 fixture tests
- Ensures toolkit is working correctly
- Uploads results on failure (7-day retention)

```bash
./dist/tests/run-fixture-tests.sh
```

#### 3. PHP Syntax Check
- Tests against **5 PHP versions**: 7.4, 8.0, 8.1, 8.2, 8.3
- Validates all `.php` files
- Ensures forward/backward compatibility
- Matrix strategy for parallel execution

#### 4. Summary
- Aggregates results from all jobs
- Posts summary to GitHub Actions UI
- Comments on PRs with results
- Fails if any check fails

### PR Comment Example
```markdown
## Code Quality Report

✅ **Performance Audit:** success
✅ **Fixture Tests:** success
✅ **PHP Syntax Check:** success (PHP 7.4-8.3)

🎉 All quality checks passed!
```

---

## Workflow 2: Scheduled Performance Audit

**File:** `.github/workflows/scheduled-audit.yml`

### Triggers
- **Schedule:** Daily at 2 AM UTC (`0 2 * * *`)
- **Manual:** Via workflow_dispatch

### Features

#### Comprehensive Audit
- Runs in **verbose mode** for detailed output
- Generates JSON report for analysis
- Uploads artifacts with 30-day retention
- Continues on error to collect all data

#### Automatic Issue Creation
- Creates GitHub issue on failure
- Includes detailed failure report
- Links to workflow run and artifacts
- Reuses existing open issue (adds comment)
- Labels: `scheduled-audit-failure`, `automated`

#### Issue Example
```markdown
## Scheduled Audit Failure Report

**Date:** 2024-12-29T02:00:00Z
**Run Number:** 42
**Workflow:** [View Run](...)

### Results
- **Performance Audit:** ❌ Failed
- **Fixture Tests:** ✅ Passed

### Action Required
Please review the workflow logs and audit report artifact...
```

---

## Workflow 3: Pull Request Checks

**File:** `.github/workflows/pr-checks.yml`

### Triggers
- **Pull Requests:** opened, synchronize, reopened, ready_for_review

### Concurrency Control
```yaml
concurrency:
  group: pr-checks-${{ github.event.pull_request.number }}
  cancel-in-progress: true
```

### Jobs

#### 1. Draft Check
- Skips all checks if PR is in draft mode
- Saves resources for work-in-progress PRs
- Outputs draft status for other jobs

#### 2. Quick Checks
- **Merge Conflicts:** Detects potential conflicts with base branch
- **File Permissions:** Ensures shell scripts are executable
- **Sensitive Data:** Scans for hardcoded passwords/API keys
- **CHANGELOG:** Reminds to update CHANGELOG.md

#### 3. Size Check
- Counts files changed, lines added/deleted
- Categorizes PR size:
  - **Small:** < 500 lines (easy to review)
  - **Medium:** 500-1000 lines (consider splitting)
  - **Large:** > 1000 lines (strongly consider splitting)

#### 4. Auto-Labeling
- Automatically adds labels based on files changed:
  - `php` - PHP files modified
  - `javascript` - JS files modified
  - `css` - CSS files modified
  - `tests` - Test files modified
  - `documentation` - Markdown files modified
  - `ci/cd` - Workflow files modified

---

## Concurrency Strategy

### Why Concurrency Control?

Without concurrency control:
```
Push commit A → Workflow starts
Push commit B → Another workflow starts
Push commit C → Another workflow starts
Result: 3 workflows running, only the last one matters
```

With concurrency control:
```
Push commit A → Workflow starts
Push commit B → Cancels A, starts new workflow
Push commit C → Cancels B, starts new workflow
Result: Only 1 workflow running (the latest)
```

### Benefits
- ✅ **Saves Resources:** No redundant runs
- ✅ **Faster Feedback:** Latest code tested immediately
- ✅ **Cost Efficient:** Reduces GitHub Actions minutes
- ✅ **Cleaner UI:** Fewer cancelled/outdated runs

### Implementation

**Per-PR Concurrency:**
```yaml
group: pr-checks-${{ github.event.pull_request.number }}
```
- Each PR has its own concurrency group
- Multiple PRs can run simultaneously
- New commits to same PR cancel previous runs

**Per-Branch Concurrency:**
```yaml
group: ${{ github.workflow }}-${{ github.ref }}
```
- Each branch has its own concurrency group
- Pushes to different branches don't interfere
- New commits to same branch cancel previous runs

**Per-Workflow Concurrency:**
```yaml
group: ${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}
```
- Combines PR number (if PR) or branch ref (if push)
- Most flexible approach
- Used in code-quality.yml

---

## Artifacts

### Performance Audit Failures
- **Path:** `dist/logs/`
- **Retention:** 7 days
- **Contains:** Detailed audit logs

### Scheduled Audit Reports
- **Path:** `audit-report.json` + `dist/logs/`
- **Retention:** 30 days
- **Contains:** JSON report + verbose logs

---

## Best Practices

### For Contributors

1. **Keep PRs Small:** Aim for < 500 lines changed
2. **Update CHANGELOG:** Add entry for your changes
3. **Fix Failures Quickly:** CI failures block merging
4. **Use Draft PRs:** Skip checks while work-in-progress
5. **Check Artifacts:** Download logs if tests fail

### For Maintainers

1. **Review Scheduled Issues:** Check daily audit failures
2. **Monitor Artifact Storage:** Clean up old artifacts if needed
3. **Update Workflows:** Keep actions versions current
4. **Adjust Concurrency:** Tune based on team size
5. **Review Labels:** Ensure auto-labels are accurate

---

## Troubleshooting

### Workflow Not Running?

**Check:**
- Branch name matches trigger patterns
- PR is not in draft mode (for PR checks)
- Workflow file syntax is valid (use yamllint)

### Tests Failing Locally But Passing in CI?

**Possible causes:**
- Different PHP version (CI tests 7.4-8.3)
- Different OS (CI uses Ubuntu)
- Missing dependencies
- File permissions

### Concurrency Issues?

**Symptoms:**
- Workflows cancelled unexpectedly
- Multiple runs for same commit

**Solutions:**
- Check concurrency group configuration
- Verify `cancel-in-progress` setting
- Review workflow triggers

---

## Manual Workflow Triggers

### Scheduled Audit
```bash
# Via GitHub UI
Actions → Scheduled Performance Audit → Run workflow

# Via GitHub CLI
gh workflow run scheduled-audit.yml
```

---

## Monitoring

### GitHub Actions Dashboard
- **Location:** Repository → Actions tab
- **View:** All workflows, runs, and artifacts
- **Filter:** By workflow, branch, status

### Email Notifications
- **Default:** Enabled for workflow failures
- **Configure:** GitHub Settings → Notifications

### Slack Integration (Optional)
See `dist/README.md` for Slack webhook setup

---

## Future Enhancements

Potential additions:
- [ ] Code coverage reporting
- [ ] Performance benchmarking
- [ ] Automated dependency updates (Dependabot)
- [ ] Security scanning (Snyk, CodeQL)
- [ ] Deployment workflows
- [ ] Release automation

---

## Resources

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [Workflow Syntax](https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions)
- [Neochrome WP Toolkit](https://github.com/NeochromeTeam/neochrome-toolkit-automated-wp-code-testing)

---

**Questions?** Open an issue or contact noel@neochro.me

