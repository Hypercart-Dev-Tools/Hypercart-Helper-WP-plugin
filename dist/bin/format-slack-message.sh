#!/usr/bin/env bash
#
# Neochrome WP Toolkit - Slack Message Formatter
#
# Converts performance audit JSON to Slack Block Kit format.
#
# Usage:
#   ./format-slack-message.sh <results.json> [--format compact|detailed]
#
# Output: JSON blocks for Slack message (to stdout)

set -euo pipefail

# Parse arguments
JSON_FILE=""
FORMAT="compact"

while [[ $# -gt 0 ]]; do
  case $1 in
    --format)
      FORMAT="$2"
      shift 2
      ;;
    *)
      JSON_FILE="$1"
      shift
      ;;
  esac
done

if [ -z "$JSON_FILE" ] || [ ! -f "$JSON_FILE" ]; then
  echo "Error: Invalid JSON file" >&2
  exit 1
fi

# Check for jq
if ! command -v jq &> /dev/null; then
  echo "Error: jq is required for Slack integration" >&2
  echo "Install: brew install jq (macOS) or apt-get install jq (Linux)" >&2
  exit 1
fi

# Extract data from JSON
ERRORS=$(jq -r '.summary.total_errors' "$JSON_FILE")
WARNINGS=$(jq -r '.summary.total_warnings' "$JSON_FILE")
BASELINED=$(jq -r '.summary.baselined' "$JSON_FILE")
EXIT_CODE=$(jq -r '.summary.exit_code' "$JSON_FILE")
TIMESTAMP=$(jq -r '.timestamp' "$JSON_FILE")
VERSION=$(jq -r '.version' "$JSON_FILE")
PATHS=$(jq -r '.paths_scanned' "$JSON_FILE")

# Determine status
if [ "$EXIT_CODE" = "0" ]; then
  STATUS="✅ PASSED"
  COLOR="#36a64f"  # Green
elif [ "$ERRORS" -gt 0 ]; then
  STATUS="❌ FAILED"
  COLOR="#ff0000"  # Red
else
  STATUS="⚠️ WARNINGS"
  COLOR="#ffaa00"  # Orange
fi

# Build GitHub context if available
GITHUB_CONTEXT=""
if [ -n "${GITHUB_REPOSITORY:-}" ]; then
  REPO_URL="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY}"
  RUN_URL="$REPO_URL/actions/runs/${GITHUB_RUN_ID:-}"
  COMMIT_URL="$REPO_URL/commit/${GITHUB_SHA:-}"
  BRANCH="${GITHUB_REF#refs/heads/}"
  
  GITHUB_CONTEXT=$(cat <<EOF
    {
      "type": "context",
      "elements": [
        {
          "type": "mrkdwn",
          "text": "📦 *Repo:* <$REPO_URL|$GITHUB_REPOSITORY>"
        },
        {
          "type": "mrkdwn",
          "text": "🌿 *Branch:* \`$BRANCH\`"
        }
      ]
    },
    {
      "type": "context",
      "elements": [
        {
          "type": "mrkdwn",
          "text": "🔗 <$RUN_URL|View Workflow Run> | <$COMMIT_URL|View Commit>"
        }
      ]
    },
EOF
)
fi

# Get top findings for detailed mode
TOP_FINDINGS=""
if [ "$FORMAT" = "detailed" ]; then
  # Get up to 3 critical/high findings
  FINDINGS=$(jq -r '.findings[] | select(.severity == "error") | "• `\(.file):\(.line)` - \(.message)"' "$JSON_FILE" | head -3)
  
  if [ -n "$FINDINGS" ]; then
    # Escape for JSON - strip outer quotes since we embed in a quoted context
    FINDINGS_ESCAPED=$(echo "$FINDINGS" | jq -Rs . | sed 's/^"//;s/"$//')
    TOP_FINDINGS=$(cat <<EOF
    {
      "type": "section",
      "text": {
        "type": "mrkdwn",
        "text": "*Top Issues:*\\n$FINDINGS_ESCAPED"
      }
    },
EOF
)
  fi
fi

# Build the Slack blocks
cat <<EOF
"blocks": [
  {
    "type": "header",
    "text": {
      "type": "plain_text",
      "text": "🚀 Neochrome Performance Audit",
      "emoji": true
    }
  },
  {
    "type": "section",
    "fields": [
      {
        "type": "mrkdwn",
        "text": "*Status:*\n$STATUS"
      },
      {
        "type": "mrkdwn",
        "text": "*Errors:*\n$ERRORS"
      },
      {
        "type": "mrkdwn",
        "text": "*Warnings:*\n$WARNINGS"
      },
      {
        "type": "mrkdwn",
        "text": "*Baselined:*\n$BASELINED"
      }
    ]
  },
  $GITHUB_CONTEXT
  $TOP_FINDINGS
  {
    "type": "context",
    "elements": [
      {
        "type": "mrkdwn",
        "text": "📁 Scanned: \`$PATHS\` | ⏰ $TIMESTAMP | v$VERSION"
      }
    ]
  }
],
"attachments": [
  {
    "color": "$COLOR",
    "fallback": "Performance audit $STATUS - $ERRORS errors, $WARNINGS warnings"
  }
]
EOF

