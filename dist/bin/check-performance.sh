#!/usr/bin/env bash
#
# Neochrome WP Toolkit - Performance Check Script
# Version: 1.0.43
#
# Local runner for grep-based performance pattern detection.
# This mirrors the GitHub Actions workflow for local development.
#
# Usage:
#   ./bin/check-performance.sh [options]
#
# Options:
#   --paths "dir1 dir2"      Paths to scan (default: current directory)
#   --format text|json       Output format (default: text)
#   --strict                 Fail on warnings (N+1 patterns)
#   --verbose                Show all matches, not just first occurrence
#   --no-log                 Disable logging to file
#   --generate-baseline      Generate .neochrome-baseline from current findings
#   --baseline <path>        Use custom baseline file path (default: .neochrome-baseline)
#   --ignore-baseline        Ignore baseline file even if present
#   --help                   Show this help message

# Note: We intentionally do NOT use 'set -e' here because:
# 1. ((var++)) returns exit code 1 when var is 0, which would cause immediate exit
# 2. grep returning no matches (exit 1) is expected behavior we handle explicitly
# 3. We manage our own error tracking with ERRORS/WARNINGS counters

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Defaults
PATHS="."
STRICT=false
VERBOSE=false
ENABLE_LOGGING=true
OUTPUT_FORMAT="text"  # text or json
# Note: 'tests' exclusion is dynamically removed when --paths targets a tests directory
EXCLUDE_DIRS="vendor node_modules .git tests"

# Baseline configuration
BASELINE_FILE=".neochrome-baseline"
GENERATE_BASELINE=false
IGNORE_BASELINE=false
BASELINE_ENABLED=false
BASELINED=0        # Total suppressed findings (covered by baseline)
STALE_ENTRIES=0    # Baseline entries with fewer matches than allowed

# Baseline storage (simple parallel arrays for broad Bash compatibility)
BASELINE_KEYS=()       # rule|file
BASELINE_ALLOWED=()    # allowed count per key
BASELINE_FOUND=()      # runtime count per key

# New baseline being generated (--generate-baseline)
NEW_BASELINE_KEYS=()
NEW_BASELINE_COUNTS=()

# JSON findings collection (initialized as empty)
declare -a JSON_FINDINGS=()
declare -a JSON_CHECKS=()

# Parse arguments
while [[ $# -gt 0 ]]; do
  case $1 in
    --paths)
      PATHS="$2"
      shift 2
      ;;
    --format)
      OUTPUT_FORMAT="$2"
      if [[ "$OUTPUT_FORMAT" != "text" && "$OUTPUT_FORMAT" != "json" ]]; then
        echo "Error: --format must be 'text' or 'json'"
        exit 1
      fi
      shift 2
      ;;
    --strict)
      STRICT=true
      shift
      ;;
    --verbose)
      VERBOSE=true
      shift
      ;;
    --no-log)
      ENABLE_LOGGING=false
      shift
      ;;
    --generate-baseline)
      GENERATE_BASELINE=true
      shift
      ;;
    --baseline)
      BASELINE_FILE="$2"
      shift 2
      ;;
    --ignore-baseline)
      IGNORE_BASELINE=true
      shift
      ;;
    --help)
      head -30 "$0" | tail -25
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      exit 1
      ;;
  esac
done

# If scanning a tests directory, remove 'tests' from exclusions
# Use portable method (no \b word boundary which is GNU-specific)
if echo "$PATHS" | grep -q "tests"; then
  EXCLUDE_DIRS="vendor node_modules .git"
fi

# Build exclude arguments
EXCLUDE_ARGS=""
for dir in $EXCLUDE_DIRS; do
  EXCLUDE_ARGS="$EXCLUDE_ARGS --exclude-dir=$dir"
done

# Setup logging
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$PLUGIN_DIR/logs"
LOG_FILE=""

if [ "$ENABLE_LOGGING" = true ]; then
  # Create logs directory if it doesn't exist
  mkdir -p "$LOG_DIR"

  # Generate timestamp in UTC (YYYY-MM-DD-HHMMSS-UTC format)
  TIMESTAMP=$(date -u +"%Y-%m-%d-%H%M%S-UTC")

  # Use appropriate file extension based on format
  if [ "$OUTPUT_FORMAT" = "json" ]; then
    LOG_FILE="$LOG_DIR/$TIMESTAMP.json"
    # For JSON mode, no header - just redirect output to log file
    exec > >(tee "$LOG_FILE")
    exec 2>&1
  else
    LOG_FILE="$LOG_DIR/$TIMESTAMP.log"

    # Write log header with metadata (text mode only)
    {
      echo "========================================================================"
      echo "Neochrome WP Toolkit - Performance Check Log"
      echo "========================================================================"
      echo ""
	      echo "Timestamp (UTC):  $(date -u +"%Y-%m-%d %H:%M:%S")"
	      echo "Script Version:   1.0.43"
      echo "Paths Scanned:    $PATHS"
      echo "Strict Mode:      $STRICT"
      echo "Verbose Mode:     $VERBOSE"
      echo "Exclude Dirs:     $EXCLUDE_DIRS"

      # Try to get git commit hash if available
      if command -v git &> /dev/null && git rev-parse --git-dir > /dev/null 2>&1; then
        GIT_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "N/A")
        GIT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "N/A")
        echo "Git Commit:       $GIT_COMMIT"
        echo "Git Branch:       $GIT_BRANCH"
      fi

      echo ""
      echo "========================================================================"
      echo ""
    } > "$LOG_FILE"

    # Redirect all output to both terminal and log file
    # We'll use process substitution to tee output
    exec > >(tee -a "$LOG_FILE")
    exec 2>&1
  fi
fi

# Function to log exit (defined early so trap can use it)
log_exit() {
  local exit_code=$1
  # Only write footer for text mode logs
  if [ "$ENABLE_LOGGING" = true ] && [ -n "$LOG_FILE" ] && [ "$OUTPUT_FORMAT" = "text" ]; then
    {
      echo ""
      echo "========================================================================"
      echo "End Timestamp (UTC): $(date -u +"%Y-%m-%d %H:%M:%S")"
      echo "Exit Code: $exit_code"
      echo "========================================================================"
    } >> "$LOG_FILE"
  fi
}

# Trap to ensure log footer is written even on unexpected exit or interrupt
if [ "$ENABLE_LOGGING" = true ]; then
  trap 'log_exit $?' EXIT
  trap 'exit 130' INT  # Ctrl+C
  trap 'exit 143' TERM # kill
fi

# ============================================================================
# JSON Output Helpers
# ============================================================================

# Escape string for JSON (handles quotes, backslashes, newlines)
json_escape() {
  local str="$1"
  str="${str//\\/\\\\}"      # Escape backslashes first
  str="${str//\"/\\\"}"      # Escape double quotes
  str="${str//$'\n'/\\n}"    # Escape newlines
  str="${str//$'\r'/\\r}"    # Escape carriage returns
  str="${str//$'\t'/\\t}"    # Escape tabs
  printf '%s' "$str"
}

# Add a finding to the JSON findings array
# Usage: add_json_finding "rule-id" "error|warning" "CRITICAL|HIGH|MEDIUM|LOW" "file" "line" "message" "code_snippet"
add_json_finding() {
  local rule_id="$1"
  local severity="$2"
  local impact="$3"
  local file="$4"
  local line="$5"
  local message="$6"
  local code="$7"

  local finding=$(cat <<EOF
{"id":"$(json_escape "$rule_id")","severity":"$severity","impact":"$impact","file":"$(json_escape "$file")","line":$line,"message":"$(json_escape "$message")","code":"$(json_escape "$code")"}
EOF
)
  JSON_FINDINGS+=("$finding")
}

# Add a check result to the JSON checks array
# Usage: add_json_check "Check Name" "CRITICAL|HIGH|MEDIUM|LOW" "passed|failed" count
add_json_check() {
  local name="$1"
  local impact="$2"
  local status="$3"
  local count="$4"

  local check=$(cat <<EOF
{"name":"$(json_escape "$name")","impact":"$impact","status":"$status","findings_count":$count}
EOF
)
  JSON_CHECKS+=("$check")
}

# Output final JSON
output_json() {
  local exit_code="$1"
  local timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

  # Build findings array
  local findings_json=""
  local first=true
  for finding in "${JSON_FINDINGS[@]}"; do
    if [ "$first" = true ]; then
      findings_json="$finding"
      first=false
    else
      findings_json="$findings_json,$finding"
    fi
  done

  # Build checks array
  local checks_json=""
  first=true
  for check in "${JSON_CHECKS[@]}"; do
    if [ "$first" = true ]; then
      checks_json="$check"
      first=false
    else
      checks_json="$checks_json,$check"
    fi
	  done

		  cat <<EOF
{
	  "version": "1.0.43",
  "timestamp": "$timestamp",
  "paths_scanned": "$(json_escape "$PATHS")",
  "strict_mode": $STRICT,
  "summary": {
    "total_errors": $ERRORS,
    "total_warnings": $WARNINGS,
    "baselined": $BASELINED,
    "stale_baseline": $STALE_ENTRIES,
    "exit_code": $exit_code
  },
  "findings": [$findings_json],
  "checks": [$checks_json]
}
EOF
}

# Conditional echo - only outputs in text mode
text_echo() {
	if [ "$OUTPUT_FORMAT" = "text" ]; then
	  	echo -e "$@"
	fi
}

# ============================================================================
# Baseline Helpers
# ============================================================================

# Normalize file paths used for baseline matching.
# This keeps baseline entries generated on one platform (e.g. macOS with
# leading "./" paths) compatible with runtime findings on another (e.g.
# Linux where grep omits the leading "./").
normalize_baseline_path() {
	local p="$1"
	case "$p" in
		./*) p="${p#./}" ;;
	esac
	printf '%s\n' "$p"
}

# Find index of a baseline key (rule|file) in BASELINE_KEYS, or -1 if not present
baseline_index() {
	local search="$1"
	local i
	for i in "${!BASELINE_KEYS[@]}"; do
		if [ "${BASELINE_KEYS[$i]}" = "$search" ]; then
			echo "$i"
			return
		fi
	done
	echo "-1"
}

# Find index of a new-baseline key (rule|file) in NEW_BASELINE_KEYS, or -1 if not present
new_baseline_index() {
	local search="$1"
	local i
	for i in "${!NEW_BASELINE_KEYS[@]}"; do
		if [ "${NEW_BASELINE_KEYS[$i]}" = "$search" ]; then
			echo "$i"
			return
		fi
	done
	echo "-1"
}

load_baseline() {
	# Skip if explicitly ignored or if generating a new baseline
	if [ "$IGNORE_BASELINE" = true ] || [ "$GENERATE_BASELINE" = true ]; then
		return
	fi

	if [ ! -f "$BASELINE_FILE" ]; then
		return
	fi

	BASELINE_ENABLED=true

	while IFS='|' read -r rule file line count hash; do
		# Skip comments and empty lines
		case "$rule" in
			"#"*|"") continue ;;
		esac

		# Basic validation
		if [ -z "$rule" ] || [ -z "$file" ] || [ -z "$count" ]; then
			continue
		fi

			# Normalize path so baseline entries are portable across environments
			file="$(normalize_baseline_path "$file")"
			local key="$rule|$file"
		BASELINE_KEYS+=("$key")
		BASELINE_ALLOWED+=("$count")
		BASELINE_FOUND+=(0)
	done < "$BASELINE_FILE"
}

# Record a hit for baseline application; returns 0 if suppressed, 1 if not suppressed
record_runtime_hit() {
	local rule="$1"
	local file="$2"
		file="$(normalize_baseline_path "$file")"
	local key="$rule|$file"

	local idx
	idx="$(baseline_index "$key")"
	if [ "$idx" -lt 0 ]; then
		return 1
	fi

	local current="${BASELINE_FOUND[$idx]}"
	[ -z "$current" ] && current=0
	current=$((current + 1))
	BASELINE_FOUND[$idx]="$current"

	local allowed="${BASELINE_ALLOWED[$idx]}"
	[ -z "$allowed" ] && allowed=0

	if [ "$current" -le "$allowed" ]; then
		BASELINED=$((BASELINED + 1))
		return 0  # suppressed
	fi

	return 1  # new finding (above baseline)
}

# Record a hit while generating a new baseline
record_new_baseline_hit() {
	local rule="$1"
	local file="$2"
		file="$(normalize_baseline_path "$file")"
	local key="$rule|$file"

	local idx
	idx="$(new_baseline_index "$key")"
	if [ "$idx" -lt 0 ]; then
		NEW_BASELINE_KEYS+=("$key")
		NEW_BASELINE_COUNTS+=(1)
		return
	fi

	local current="${NEW_BASELINE_COUNTS[$idx]}"
	[ -z "$current" ] && current=0
	current=$((current + 1))
	NEW_BASELINE_COUNTS[$idx]="$current"
}

# Returns 0 if this finding should be suppressed by baseline, 1 otherwise
should_suppress_finding() {
	local rule="$1"
	local file="$2"

	# When generating baseline we never suppress, but we do record counts
	if [ "$GENERATE_BASELINE" = true ]; then
		record_new_baseline_hit "$rule" "$file"
		return 1
	fi

	# When baseline is ignored or not enabled, do not suppress
	if [ "$IGNORE_BASELINE" = true ] || [ "$BASELINE_ENABLED" = false ]; then
		return 1
	fi

	# Apply existing baseline
	if record_runtime_hit "$rule" "$file"; then
		return 0
	fi

	return 1
}

check_stale_entries() {
	# Only meaningful when a baseline is loaded and not ignored
	if [ "$BASELINE_ENABLED" = false ] || [ "$IGNORE_BASELINE" = true ]; then
		return
	fi

	local i
	for i in "${!BASELINE_KEYS[@]}"; do
		local key="${BASELINE_KEYS[$i]}"
		local allowed="${BASELINE_ALLOWED[$i]}"
		local found="${BASELINE_FOUND[$i]}"

		[ -z "$allowed" ] && allowed=0
		[ -z "$found" ] && found=0

		if [ "$found" -lt "$allowed" ]; then
			STALE_ENTRIES=$((STALE_ENTRIES + 1))
			# Hint to help maintainers clean up the baseline over time
			text_echo "  \u2139 Baseline can be reduced: ${key} (allowed: ${allowed}, found: ${found})"
		fi
	done
}

generate_baseline_file() {
	if [ "$GENERATE_BASELINE" != true ]; then
		return
	fi

	# Ensure directory exists
	local dir
	dir="$(dirname "$BASELINE_FILE")"
	if [ ! -d "$dir" ]; then
		mkdir -p "$dir" 2>/dev/null || true
	fi

	local total=0
	local i
	for i in "${!NEW_BASELINE_KEYS[@]}"; do
		local count="${NEW_BASELINE_COUNTS[$i]}"
		[ -z "$count" ] && count=0
		total=$((total + count))
	done

	local tmp
	tmp="$(mktemp 2>/dev/null || echo "/tmp/neochrome-baseline.$$")"

	for i in "${!NEW_BASELINE_KEYS[@]}"; do
		local key="${NEW_BASELINE_KEYS[$i]}"
		local count="${NEW_BASELINE_COUNTS[$i]}"
		[ -z "$count" ] && count=0

		local rule="${key%%|*}"
		local file="${key#*|}"
		# line and snippet_hash are placeholders for now; matching is done on rule+file only
		echo "${rule}|${file}|0|${count}|*" >> "$tmp"
	done

	{
		echo "# .neochrome-baseline"
		echo "# Generated: $(date '+%Y-%m-%d %H:%M:%S')"
		echo "# Tool: Neochrome WP Toolkit $(grep -m1 'Version:' "$0" 2>/dev/null | sed 's/^# Version: //')"
		echo "# Total baselined: ${total}"
		echo "#"
		echo "# Format: rule|file|line|count|snippet_hash"
		echo
		sort "$tmp"
	} > "$BASELINE_FILE"

	rm -f "$tmp" 2>/dev/null || true

	text_echo "${GREEN}Baseline file written to ${BASELINE_FILE} (${total} total findings).${NC}"
}

# ============================================================================
# Main Script Output
# ============================================================================

# Load existing baseline (if any) before running checks
load_baseline

			text_echo "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
			text_echo "${BLUE}  Neochrome WP Toolkit - Performance Checker v1.0.43${NC}"
		text_echo "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
text_echo ""
text_echo "Scanning paths: $PATHS"
text_echo "Strict mode: $STRICT"
if [ "$ENABLE_LOGGING" = true ] && [ "$OUTPUT_FORMAT" = "text" ]; then
	text_echo "Logging to: $LOG_FILE"
fi
text_echo ""

ERRORS=0
WARNINGS=0

# Function to run a check with impact scoring
# Usage: run_check "ERROR|WARNING" "CRITICAL|HIGH|MEDIUM|LOW" "Check name" "rule-id" patterns...
run_check() {
  local level="$1"    # ERROR or WARNING
  local impact="$2"   # CRITICAL, HIGH, MEDIUM, or LOW
  local name="$3"     # Check name
  local rule_id="$4"  # Rule ID for JSON output
  shift 4             # Remove first four args, rest are patterns
  local patterns="$@" # All remaining args are grep patterns

  # Format impact badge
  local impact_badge=""
  case $impact in
    CRITICAL) impact_badge="${RED}[CRITICAL]${NC}" ;;
    HIGH)     impact_badge="${RED}[HIGH]${NC}" ;;
    MEDIUM)   impact_badge="${YELLOW}[MEDIUM]${NC}" ;;
    LOW)      impact_badge="${BLUE}[LOW]${NC}" ;;
  esac

  text_echo "${BLUE}▸ $name ${impact_badge}${NC}"

	  # Run grep with all patterns
	  local result
	  local finding_count=0
	  local severity="error"
	  [ "$level" = "WARNING" ] && severity="warning"

  if result=$(grep -rHn $EXCLUDE_ARGS --include="*.php" $patterns $PATHS 2>/dev/null); then
	    local visible_result=""
	    local visible_count=0

	    # Collect findings for JSON output, applying baseline suppression per match
	    while IFS= read -r line; do
	      [ -z "$line" ] && continue
	      # Parse grep output: file:line:code
	      local file=$(echo "$line" | cut -d: -f1)
	      local lineno=$(echo "$line" | cut -d: -f2)
	      local code=$(echo "$line" | cut -d: -f3-)

	      local suppress=1
	      if should_suppress_finding "$rule_id" "$file"; then
	        suppress=0
	      fi

	      if [ "$suppress" -ne 0 ]; then
	        # Not covered by baseline - include in output and JSON
	        if [ -z "$visible_result" ]; then
	          visible_result="$line"
	        else
	          visible_result="${visible_result}
$line"
	        fi
	        visible_count=$((visible_count + 1))
	        add_json_finding "$rule_id" "$severity" "$impact" "$file" "$lineno" "$name" "$code"
	      fi
	    done <<< "$result"

	    finding_count=$visible_count

	    if [ "$finding_count" -gt 0 ]; then
	      if [ "$level" = "ERROR" ]; then
	        text_echo "${RED}  ✗ FAILED${NC}"
	        if [ "$OUTPUT_FORMAT" = "text" ]; then
	          echo "$visible_result" | head -10
	          if [ "$VERBOSE" = "false" ] && [ "$finding_count" -gt 10 ]; then
	            echo "  ... and more (use --verbose to see all)"
	          fi
	        fi
	        ((ERRORS++))
	      else
	        text_echo "${YELLOW}  ⚠ WARNING${NC}"
	        if [ "$OUTPUT_FORMAT" = "text" ]; then
	          echo "$visible_result" | head -5
	        fi
	        ((WARNINGS++))
	      fi
	      add_json_check "$name" "$impact" "failed" "$finding_count"
	    else
	      # All matches were covered by the baseline
	      text_echo "${GREEN}  ✓ Passed (all issues covered by baseline)${NC}"
	      add_json_check "$name" "$impact" "passed" 0
	    fi
	  else
	    text_echo "${GREEN}  ✓ Passed${NC}"
	    add_json_check "$name" "$impact" "passed" 0
	  fi
	  text_echo ""
}

text_echo "${RED}━━━ CRITICAL CHECKS (will fail build) ━━━${NC}"
text_echo ""

text_echo "${BLUE}▸ Unbounded AJAX polling (setInterval + fetch/ajax) ${RED}[HIGH]${NC}"
AJAX_POLLING=false
AJAX_POLLING_FINDING_COUNT=0
AJAX_POLLING_VISIBLE=""
POLLING_MATCHES=$(grep -rHn $EXCLUDE_ARGS --include="*.js" -E "setInterval[[:space:]]*\\(" $PATHS 2>/dev/null || true)
if [ -n "$POLLING_MATCHES" ]; then
  while IFS= read -r match; do
    [ -z "$match" ] && continue
    file=$(echo "$match" | cut -d: -f1)
    lineno=$(echo "$match" | cut -d: -f2)
    code=$(echo "$match" | cut -d: -f3-)

    if ! [[ "$lineno" =~ ^[0-9][0-9]*$ ]]; then
      continue
    fi

    start_line=$lineno
    end_line=$((lineno + 5))
    context=$(sed -n "${start_line},${end_line}p" "$file" 2>/dev/null)

    if echo "$context" | grep -qiE "\\.ajax|fetch\\(|axios\\(|XMLHttpRequest|wp\\.apiFetch"; then
      if should_suppress_finding "ajax-polling-setinterval" "$file"; then
        continue
      fi

      AJAX_POLLING=true
      ((AJAX_POLLING_FINDING_COUNT++))
      add_json_finding "ajax-polling-setinterval" "error" "HIGH" "$file" "${lineno:-0}" "AJAX polling via setInterval without rate limits" "$code"
      if [ -z "$AJAX_POLLING_VISIBLE" ]; then
        AJAX_POLLING_VISIBLE="$match"
      else
        AJAX_POLLING_VISIBLE="${AJAX_POLLING_VISIBLE}
$match"
      fi
    fi
  done <<< "$POLLING_MATCHES"
fi
if [ "$AJAX_POLLING" = true ]; then
  text_echo "${RED}  ✗ FAILED${NC}"
  if [ "$OUTPUT_FORMAT" = "text" ] && [ -n "$AJAX_POLLING_VISIBLE" ]; then
    echo "$AJAX_POLLING_VISIBLE" | head -5
  fi
  ((ERRORS++))
  add_json_check "Unbounded AJAX polling (setInterval + fetch/ajax)" "HIGH" "failed" "$AJAX_POLLING_FINDING_COUNT"
else
  text_echo "${GREEN}  ✓ Passed${NC}"
  add_json_check "Unbounded AJAX polling (setInterval + fetch/ajax)" "HIGH" "passed" 0
fi
text_echo ""

text_echo "${BLUE}▸ REST endpoints without pagination/limits ${RED}[CRITICAL]${NC}"
REST_UNBOUNDED=false
REST_FINDING_COUNT=0
REST_VISIBLE=""
REST_MATCHES=$(grep -rHn $EXCLUDE_ARGS --include="*.php" -E "register_rest_route[[:space:]]*\\(" $PATHS 2>/dev/null || true)
if [ -n "$REST_MATCHES" ]; then
  while IFS= read -r match; do
    [ -z "$match" ] && continue
    file=$(echo "$match" | cut -d: -f1)
    lineno=$(echo "$match" | cut -d: -f2)
    code=$(echo "$match" | cut -d: -f3-)

    if ! [[ "$lineno" =~ ^[0-9][0-9]*$ ]]; then
      continue
    fi

    start_line=$lineno
    end_line=$((lineno + 15))
    context=$(sed -n "${start_line},${end_line}p" "$file" 2>/dev/null)

    if ! echo "$context" | grep -qiE "'per_page'|\"per_page\"|'page'|\"page\"|'limit'|\"limit\"|pagination|paged|per_page"; then
      if should_suppress_finding "rest-endpoint-unbounded" "$file"; then
        continue
      fi

      REST_UNBOUNDED=true
      ((REST_FINDING_COUNT++))
      add_json_finding "rest-endpoint-unbounded" "error" "CRITICAL" "$file" "${lineno:-0}" "register_rest_route without per_page/limit pagination guard" "$code"
      if [ -z "$REST_VISIBLE" ]; then
        REST_VISIBLE="$match"
      else
        REST_VISIBLE="${REST_VISIBLE}
$match"
      fi
    fi
  done <<< "$REST_MATCHES"
fi
if [ "$REST_UNBOUNDED" = true ]; then
  text_echo "${RED}  ✗ FAILED${NC}"
  if [ "$OUTPUT_FORMAT" = "text" ] && [ -n "$REST_VISIBLE" ]; then
    echo "$REST_VISIBLE" | head -5
  fi
  ((ERRORS++))
  add_json_check "REST endpoints without pagination/limits" "CRITICAL" "failed" "$REST_FINDING_COUNT"
else
  text_echo "${GREEN}  ✓ Passed${NC}"
  add_json_check "REST endpoints without pagination/limits" "CRITICAL" "passed" 0
fi
text_echo ""

text_echo "${BLUE}▸ wp_ajax handlers without nonce validation ${RED}[HIGH]${NC}"
AJAX_NONCE_FAIL=false
AJAX_NONCE_FINDING_COUNT=0
AJAX_FILES=$(grep -rln $EXCLUDE_ARGS --include="*.php" -e "wp_ajax" $PATHS 2>/dev/null || true)
if [ -n "$AJAX_FILES" ]; then
  for file in $AJAX_FILES; do
    hook_count=$(grep -E "wp_ajax" "$file" 2>/dev/null | wc -l | tr -d '[:space:]')
    nonce_count=$(grep -E "check_ajax_referer[[:space:]]*\\(|wp_verify_nonce[[:space:]]*\\(" "$file" 2>/dev/null | wc -l | tr -d '[:space:]')

    if [ -z "$hook_count" ] || [ "$hook_count" -eq 0 ]; then
      continue
    fi

	    # Require at least one nonce validation somewhere in the file
	    # if any wp_ajax hook is present. This avoids false positives in
	    # common patterns like shared handlers for wp_ajax_/wp_ajax_nopriv_
	    # while still flagging completely unprotected files.
	    if [ -z "$nonce_count" ] || [ "$nonce_count" -eq 0 ]; then
	      :
	    else
	      continue
	    fi
    if should_suppress_finding "wp-ajax-no-nonce" "$file"; then
      continue
    fi

    lineno=$(grep -n "wp_ajax" "$file" 2>/dev/null | head -1 | cut -d: -f1)
    code=$(grep -n "wp_ajax" "$file" 2>/dev/null | head -1 | cut -d: -f2-)
    text_echo "  $file: wp_ajax handler missing nonce validation"
    add_json_finding "wp-ajax-no-nonce" "error" "HIGH" "$file" "${lineno:-0}" "wp_ajax handler missing nonce validation" "$code"
    AJAX_NONCE_FAIL=true
    ((AJAX_NONCE_FINDING_COUNT++))
  done
fi
if [ "$AJAX_NONCE_FAIL" = true ]; then
  text_echo "${RED}  ✗ FAILED${NC}"
  ((ERRORS++))
  add_json_check "wp_ajax handlers without nonce validation" "HIGH" "failed" "$AJAX_NONCE_FINDING_COUNT"
else
  text_echo "${GREEN}  ✓ Passed${NC}"
  add_json_check "wp_ajax handlers without nonce validation" "HIGH" "passed" 0
fi
text_echo ""

run_check "ERROR" "CRITICAL" "Unbounded posts_per_page" "unbounded-posts-per-page" \
  "-e posts_per_page[[:space:]]*=>[[:space:]]*-1"

run_check "ERROR" "CRITICAL" "Unbounded numberposts" "unbounded-numberposts" \
  "-e numberposts[[:space:]]*=>[[:space:]]*-1"

run_check "ERROR" "CRITICAL" "nopaging => true" "nopaging-true" \
  "-e nopaging[[:space:]]*=>[[:space:]]*true"

run_check "ERROR" "CRITICAL" "Unbounded wc_get_orders limit" "unbounded-wc-get-orders" \
  "-e 'limit'[[:space:]]*=>[[:space:]]*-1"

# get_terms check - more complex, needs context analysis
text_echo "${BLUE}▸ get_terms without number limit ${RED}[CRITICAL]${NC}"
TERMS_FILES=$(grep -rln $EXCLUDE_ARGS --include="*.php" -e "get_terms[[:space:]]*(" $PATHS 2>/dev/null || true)
TERMS_UNBOUNDED=false
TERMS_FINDING_COUNT=0
if [ -n "$TERMS_FILES" ]; then
  for file in $TERMS_FILES; do
    # Check if file has get_terms without 'number' or "number" nearby (within 5 lines)
    # Support both single and double quotes
	    if ! grep -A5 "get_terms[[:space:]]*(" "$file" 2>/dev/null | grep -q -e "'number'" -e '"number"'; then
	      # Apply baseline suppression per file
	      if ! should_suppress_finding "get-terms-no-limit" "$file"; then
	        text_echo "  $file: get_terms() may be missing 'number' parameter"
	        # Get line number for JSON
	        lineno=$(grep -n "get_terms[[:space:]]*(" "$file" 2>/dev/null | head -1 | cut -d: -f1)
	        add_json_finding "get-terms-no-limit" "error" "CRITICAL" "$file" "${lineno:-0}" "get_terms() may be missing 'number' parameter" "get_terms("
	        TERMS_UNBOUNDED=true
	        ((TERMS_FINDING_COUNT++))
	      fi
	    fi
  done
fi
if [ "$TERMS_UNBOUNDED" = true ]; then
  text_echo "${RED}  ✗ FAILED${NC}"
  ((ERRORS++))
  add_json_check "get_terms without number limit" "CRITICAL" "failed" "$TERMS_FINDING_COUNT"
else
  text_echo "${GREEN}  ✓ Passed${NC}"
  add_json_check "get_terms without number limit" "CRITICAL" "passed" 0
fi
text_echo ""

# pre_get_posts unbounded check - files that hook pre_get_posts and set unbounded queries
text_echo "${BLUE}▸ pre_get_posts forcing unbounded queries ${RED}[CRITICAL]${NC}"
PRE_GET_POSTS_UNBOUNDED=false
PRE_GET_POSTS_FINDING_COUNT=0
PRE_GET_POSTS_FILES=$(grep -rln $EXCLUDE_ARGS --include="*.php" -e "add_action.*pre_get_posts\|add_filter.*pre_get_posts" $PATHS 2>/dev/null || true)
if [ -n "$PRE_GET_POSTS_FILES" ]; then
  for file in $PRE_GET_POSTS_FILES; do
    # Check if file sets posts_per_page to -1 or nopaging to true
	    if grep -q "set[[:space:]]*([[:space:]]*['\"]posts_per_page['\"][[:space:]]*,[[:space:]]*-1" "$file" 2>/dev/null || \
	       grep -q "set[[:space:]]*([[:space:]]*['\"]nopaging['\"][[:space:]]*,[[:space:]]*true" "$file" 2>/dev/null; then
	      if ! should_suppress_finding "pre-get-posts-unbounded" "$file"; then
	        text_echo "  $file: pre_get_posts hook sets unbounded query"
	        lineno=$(grep -n "pre_get_posts" "$file" 2>/dev/null | head -1 | cut -d: -f1)
	        add_json_finding "pre-get-posts-unbounded" "error" "CRITICAL" "$file" "${lineno:-0}" "pre_get_posts hook sets unbounded query" "pre_get_posts"
	        PRE_GET_POSTS_UNBOUNDED=true
	        ((PRE_GET_POSTS_FINDING_COUNT++))
	      fi
	    fi
  done
fi
if [ "$PRE_GET_POSTS_UNBOUNDED" = true ]; then
  text_echo "${RED}  ✗ FAILED${NC}"
  ((ERRORS++))
  add_json_check "pre_get_posts forcing unbounded queries" "CRITICAL" "failed" "$PRE_GET_POSTS_FINDING_COUNT"
else
  text_echo "${GREEN}  ✓ Passed${NC}"
  add_json_check "pre_get_posts forcing unbounded queries" "CRITICAL" "passed" 0
fi
text_echo ""

# Unbounded direct SQL on terms tables
# Look for lines with wpdb->terms or wpdb->term_taxonomy that don't have LIMIT on the same line
text_echo "${BLUE}▸ Unbounded SQL on wp_terms/wp_term_taxonomy ${RED}[HIGH]${NC}"
TERMS_SQL_UNBOUNDED=false
TERMS_SQL_FINDING_COUNT=0
# Find lines referencing terms tables in SQL context
TERMS_SQL_MATCHES=$(grep -rHn $EXCLUDE_ARGS --include="*.php" -E '\$wpdb->(terms|term_taxonomy)' $PATHS 2>/dev/null || true)
	if [ -n "$TERMS_SQL_MATCHES" ]; then
	  # Filter out lines that have LIMIT (case-insensitive to catch both 'LIMIT' and 'limit')
	  UNBOUNDED_MATCHES=$(echo "$TERMS_SQL_MATCHES" | grep -vi "LIMIT" || true)
	  if [ -n "$UNBOUNDED_MATCHES" ]; then
	    VISIBLE_MATCHES=""
	    while IFS= read -r line; do
	      [ -z "$line" ] && continue
	      _file=$(echo "$line" | cut -d: -f1)
	      _lineno=$(echo "$line" | cut -d: -f2)
	      _code=$(echo "$line" | cut -d: -f3-)

	      if ! should_suppress_finding "unbounded-terms-sql" "$_file"; then
	        TERMS_SQL_UNBOUNDED=true
	        ((TERMS_SQL_FINDING_COUNT++))
	        add_json_finding "unbounded-terms-sql" "error" "HIGH" "$_file" "${_lineno:-0}" "Unbounded SQL on wp_terms/wp_term_taxonomy" "$_code"
	        if [ -z "$VISIBLE_MATCHES" ]; then
	          VISIBLE_MATCHES="$line"
	        else
	          VISIBLE_MATCHES="${VISIBLE_MATCHES}
$line"
	        fi
	      fi
	    done <<< "$UNBOUNDED_MATCHES"

	    if [ "$TERMS_SQL_UNBOUNDED" = true ] && [ "$OUTPUT_FORMAT" = "text" ]; then
	      echo "$VISIBLE_MATCHES" | head -5 | while read line; do
	        echo "  $line"
	      done
	    fi
	  fi
	fi
if [ "$TERMS_SQL_UNBOUNDED" = true ]; then
  text_echo "${RED}  ✗ FAILED${NC}"
  ((ERRORS++))
  add_json_check "Unbounded SQL on wp_terms/wp_term_taxonomy" "HIGH" "failed" "$TERMS_SQL_FINDING_COUNT"
else
  text_echo "${GREEN}  ✓ Passed${NC}"
  add_json_check "Unbounded SQL on wp_terms/wp_term_taxonomy" "HIGH" "passed" 0
fi

text_echo ""
text_echo "${YELLOW}━━━ WARNING CHECKS (review recommended) ━━━${NC}"
text_echo ""

# Enhanced timezone check - skip lines with phpcs:ignore comments
text_echo "${BLUE}▸ Timezone-sensitive patterns (current_time/date) ${YELLOW}[LOW]${NC}"
TZ_WARNINGS=0
TZ_FINDING_COUNT=0
TZ_MATCHES=$(grep -rHn $EXCLUDE_ARGS --include="*.php" \
  -e "current_time[[:space:]]*([[:space:]]*['\"]timestamp" \
  -e "date[[:space:]]*([[:space:]]*['\"][YmdHis-]*['\"]" \
  $PATHS 2>/dev/null || true)

if [ -n "$TZ_MATCHES" ]; then
  # Filter out lines that have phpcs:ignore nearby (check line before)
  FILTERED_MATCHES=""
  while IFS= read -r match; do
    file_line=$(echo "$match" | cut -d: -f1-2)
    file=$(echo "$match" | cut -d: -f1)
    line_num=$(echo "$match" | cut -d: -f2)
    code=$(echo "$match" | cut -d: -f3-)

	    # Defensive: ensure line number is numeric before doing arithmetic.
	    # On some platforms/tools, unexpected grep output can sneak in here
	    # (e.g. warnings or lines without the usual file:line:code format),
	    # which would make "$line_num" non-numeric and break $((...)).
	    if ! [[ "$line_num" =~ ^[0-9][0-9]*$ ]]; then
	      if [ "${NEOCHROME_DEBUG:-}" = "1" ] && [ "$OUTPUT_FORMAT" = "text" ]; then
	        text_echo "  [DEBUG] Skipping non-numeric timezone match: $match"
	      fi
	      continue
	    fi

    # Check if there's a phpcs:ignore comment on the line before or same line
    prev_line=$((line_num - 1))
    has_ignore=false

    # Check if current line or previous line has phpcs:ignore
    if sed -n "${prev_line}p;${line_num}p" "$file" 2>/dev/null | grep -q "phpcs:ignore"; then
      has_ignore=true
    fi

	    if [ "$has_ignore" = false ]; then
	      if ! should_suppress_finding "timezone-sensitive-pattern" "$file"; then
	        FILTERED_MATCHES="${FILTERED_MATCHES}${match}"$'\n'
	        add_json_finding "timezone-sensitive-pattern" "warning" "LOW" "$file" "$line_num" "Timezone-sensitive pattern without phpcs:ignore" "$code"
	        ((TZ_WARNINGS++)) || true
	        ((TZ_FINDING_COUNT++)) || true
	      fi
	    fi
  done <<< "$TZ_MATCHES"

  if [ "$TZ_WARNINGS" -gt 0 ]; then
    text_echo "${YELLOW}  ⚠ WARNING ($TZ_WARNINGS occurrence(s) without phpcs:ignore)${NC}"
    if [ "$OUTPUT_FORMAT" = "text" ]; then
      if [ "$VERBOSE" = "true" ]; then
        echo "$FILTERED_MATCHES"
      else
        echo "$FILTERED_MATCHES" | head -5
        if [ "$TZ_WARNINGS" -gt 5 ]; then
          echo "  ... and $((TZ_WARNINGS - 5)) more (use --verbose to see all)"
        fi
      fi
    fi
    ((WARNINGS++))
    add_json_check "Timezone-sensitive patterns (current_time/date)" "LOW" "failed" "$TZ_FINDING_COUNT"
  else
    text_echo "${GREEN}  ✓ Passed (all occurrences have phpcs:ignore)${NC}"
    add_json_check "Timezone-sensitive patterns (current_time/date)" "LOW" "passed" 0
  fi
else
  text_echo "${GREEN}  ✓ Passed${NC}"
  add_json_check "Timezone-sensitive patterns (current_time/date)" "LOW" "passed" 0
fi
text_echo ""

run_check "WARNING" "HIGH" "Randomized ordering (ORDER BY RAND)" "order-by-rand" \
  "-e orderby[[:space:]]*=>[[:space:]]*['\"]rand['\"]" \
  "-E ORDER[[:space:]]+BY[[:space:]]+RAND\("

# LIKE queries with leading wildcards
text_echo "${BLUE}▸ LIKE queries with leading wildcards ${YELLOW}[MEDIUM]${NC}"
LIKE_WARNINGS=0
LIKE_ISSUES=""
LIKE_FINDING_COUNT=0

# Pattern 1: WP_Query meta_query with compare => 'LIKE' and value starting with %
# Look for 'compare' => 'LIKE' patterns in meta_query context
META_LIKE=$(grep -rHn $EXCLUDE_ARGS --include="*.php" \
  -E "'compare'[[:space:]]*=>[[:space:]]*['\"]LIKE['\"]" \
  $PATHS 2>/dev/null || true)

	if [ -n "$META_LIKE" ]; then
	  # Check each match for nearby % wildcard at start of value
	  while IFS= read -r match; do
	    [ -z "$match" ] && continue
	    file=$(echo "$match" | cut -d: -f1)
	      line_num=$(echo "$match" | cut -d: -f2)
	      code=$(echo "$match" | cut -d: -f3-)

	      # Defensive: ensure line number is numeric before doing arithmetic.
	      # On some platforms/tools, unexpected grep output can sneak in here,
	      # which would make "$line_num" non-numeric and break $((...)).
	      if ! [[ "$line_num" =~ ^[0-9][0-9]*$ ]]; then
	        if [ "${NEOCHROME_DEBUG:-}" = "1" ] && [ "$OUTPUT_FORMAT" = "text" ]; then
	          text_echo "  [DEBUG] Skipping non-numeric LIKE match: $match"
	        fi
	        continue
	      fi

	      # Look at surrounding lines (5 before and after) for value starting with %
	      start_line=$((line_num - 5))
	      [ "$start_line" -lt 1 ] && start_line=1
	      end_line=$((line_num + 5))

	    # Check for 'value' => '%... pattern nearby
	    if sed -n "${start_line},${end_line}p" "$file" 2>/dev/null | grep -qE "'value'[[:space:]]*=>[[:space:]]*['\"]%"; then
	      if ! should_suppress_finding "like-leading-wildcard" "$file"; then
	        LIKE_ISSUES="${LIKE_ISSUES}${match}"$'\n'
	        add_json_finding "like-leading-wildcard" "warning" "MEDIUM" "$file" "$line_num" "LIKE query with leading wildcard prevents index use" "$code"
	        ((LIKE_WARNINGS++)) || true
	        ((LIKE_FINDING_COUNT++)) || true
	      fi
	    fi
	  done <<< "$META_LIKE"
	fi

# Pattern 2: Raw SQL with LIKE '%... (leading wildcard)
# Only match actual code, not comments (lines starting with * or //)
SQL_LIKE=$(grep -rHn $EXCLUDE_ARGS --include="*.php" \
  -E "LIKE[[:space:]]+['\"]%" \
  $PATHS 2>/dev/null | grep -v "^[^:]*:[0-9]*:[[:space:]]*//" | grep -v "^[^:]*:[0-9]*:[[:space:]]*\*" || true)

	if [ -n "$SQL_LIKE" ]; then
	  while IFS= read -r match; do
	    [ -z "$match" ] && continue
	    file=$(echo "$match" | cut -d: -f1)
	    line_num=$(echo "$match" | cut -d: -f2)
	    code=$(echo "$match" | cut -d: -f3-)
	    if ! should_suppress_finding "like-leading-wildcard" "$file"; then
	      LIKE_ISSUES="${LIKE_ISSUES}${match}"$'\n'
	      add_json_finding "like-leading-wildcard" "warning" "MEDIUM" "$file" "$line_num" "LIKE query with leading wildcard prevents index use" "$code"
	      ((LIKE_WARNINGS++)) || true
	      ((LIKE_FINDING_COUNT++)) || true
	    fi
	  done <<< "$SQL_LIKE"
	fi

if [ "$LIKE_WARNINGS" -gt 0 ]; then
  text_echo "${YELLOW}  ⚠ WARNING - LIKE queries with leading wildcards prevent index use:${NC}"
  if [ "$OUTPUT_FORMAT" = "text" ]; then
    if [ "$VERBOSE" = "true" ]; then
      echo "$LIKE_ISSUES"
    else
      echo "$LIKE_ISSUES" | head -5
      if [ "$LIKE_WARNINGS" -gt 5 ]; then
        echo "  ... and $((LIKE_WARNINGS - 5)) more (use --verbose to see all)"
      fi
    fi
  fi
  ((WARNINGS++))
  add_json_check "LIKE queries with leading wildcards" "MEDIUM" "failed" "$LIKE_FINDING_COUNT"
else
  text_echo "${GREEN}  ✓ Passed${NC}"
  add_json_check "LIKE queries with leading wildcards" "MEDIUM" "passed" 0
fi
text_echo ""

# N+1 pattern check (simplified)
text_echo "${BLUE}▸ Potential N+1 patterns (meta in loops) ${YELLOW}[MEDIUM]${NC}"
	N1_FILES=$(grep -rl $EXCLUDE_ARGS --include="*.php" -e "get_post_meta\|get_term_meta" $PATHS 2>/dev/null | \
	           xargs -I{} grep -l "foreach\|while[[:space:]]*(" {} 2>/dev/null | head -5 || true)
	N1_FINDING_COUNT=0
	VISIBLE_N1_FILES=""
	if [ -n "$N1_FILES" ]; then
	  # Collect findings, applying baseline per file
	  while IFS= read -r f; do
	    [ -z "$f" ] && continue
	    if ! should_suppress_finding "n-plus-1-pattern" "$f"; then
	      VISIBLE_N1_FILES="${VISIBLE_N1_FILES}${f}"$'\n'
	      add_json_finding "n-plus-1-pattern" "warning" "MEDIUM" "$f" "0" "File may contain N+1 query pattern (meta in loops)" ""
	      ((N1_FINDING_COUNT++)) || true
	    fi
	  done <<< "$N1_FILES"

	  if [ "$N1_FINDING_COUNT" -gt 0 ]; then
	    text_echo "${YELLOW}  ⚠ Files with potential N+1 patterns:${NC}"
	    if [ "$OUTPUT_FORMAT" = "text" ]; then
	      echo "$VISIBLE_N1_FILES" | while read f; do [ -n "$f" ] && echo "    - $f"; done
	    fi
	    ((WARNINGS++))
	    add_json_check "Potential N+1 patterns (meta in loops)" "MEDIUM" "failed" "$N1_FINDING_COUNT"
	  else
	    text_echo "${GREEN}  ✓ No obvious N+1 patterns${NC}"
	    add_json_check "Potential N+1 patterns (meta in loops)" "MEDIUM" "passed" 0
	  fi
	else
	  text_echo "${GREEN}  ✓ No obvious N+1 patterns${NC}"
	  add_json_check "Potential N+1 patterns (meta in loops)" "MEDIUM" "passed" 0
	fi
text_echo ""

# Transient abuse check - transients without expiration
text_echo "${BLUE}▸ Transients without expiration ${YELLOW}[MEDIUM]${NC}"
TRANSIENT_MATCHES=$(grep -rHn $EXCLUDE_ARGS --include="*.php" -E "set_transient[[:space:]]*\(" $PATHS 2>/dev/null || true)
TRANSIENT_ABUSE=false
TRANSIENT_ISSUES=""
TRANSIENT_FINDING_COUNT=0

if [ -n "$TRANSIENT_MATCHES" ]; then
  while IFS= read -r match; do
    # Check if line contains a third parameter (expiration)
    # set_transient( $key, $value, $expiration ) - needs 3 params
    # Count commas in the line - should have at least 2 for proper usage
    comma_count=$(echo "$match" | tr -cd ',' | wc -c)
	    if [ "$comma_count" -lt 2 ]; then
	      file=$(echo "$match" | cut -d: -f1)
	      line_num=$(echo "$match" | cut -d: -f2)
	      code=$(echo "$match" | cut -d: -f3-)
	      if ! should_suppress_finding "transient-no-expiration" "$file"; then
	        TRANSIENT_ISSUES="${TRANSIENT_ISSUES}${match}"$'\n'
	        add_json_finding "transient-no-expiration" "warning" "MEDIUM" "$file" "$line_num" "Transient may be missing expiration parameter" "$code"
	        TRANSIENT_ABUSE=true
	        ((TRANSIENT_FINDING_COUNT++)) || true
	      fi
	    fi
  done <<< "$TRANSIENT_MATCHES"
fi

if [ "$TRANSIENT_ABUSE" = true ]; then
  text_echo "${YELLOW}  ⚠ WARNING - Transients may be missing expiration parameter:${NC}"
  if [ "$OUTPUT_FORMAT" = "text" ]; then
    echo "$TRANSIENT_ISSUES" | head -5
  fi
  ((WARNINGS++))
  add_json_check "Transients without expiration" "MEDIUM" "failed" "$TRANSIENT_FINDING_COUNT"
else
  text_echo "${GREEN}  ✓ Passed${NC}"
  add_json_check "Transients without expiration" "MEDIUM" "passed" 0
fi
text_echo ""

	# Evaluate baseline entries for staleness before computing exit code / JSON
	check_stale_entries

	# Generate baseline file if requested
	generate_baseline_file

	# Determine exit code
EXIT_CODE=0
if [ "$ERRORS" -gt 0 ]; then
  EXIT_CODE=1
elif [ "$STRICT" = "true" ] && [ "$WARNINGS" -gt 0 ]; then
  EXIT_CODE=1
fi

# Output based on format
if [ "$OUTPUT_FORMAT" = "json" ]; then
  output_json "$EXIT_CODE"
else
  # Summary (text mode)
  text_echo "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
  text_echo "${BLUE}  SUMMARY${NC}"
  text_echo "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
  text_echo ""
  text_echo "  Errors:   ${RED}$ERRORS${NC}"
  text_echo "  Warnings: ${YELLOW}$WARNINGS${NC}"
  text_echo ""

  if [ "$ERRORS" -gt 0 ]; then
    text_echo "${RED}✗ Check failed with $ERRORS error(s)${NC}"
  elif [ "$STRICT" = "true" ] && [ "$WARNINGS" -gt 0 ]; then
    text_echo "${YELLOW}✗ Check failed in strict mode with $WARNINGS warning(s)${NC}"
  else
    text_echo "${GREEN}✓ All critical checks passed!${NC}"
  fi
fi

exit $EXIT_CODE
