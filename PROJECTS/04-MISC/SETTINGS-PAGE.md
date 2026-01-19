# Hypercart Helper Settings Page

## Location
WordPress Admin → Settings → Hypercart Helper

## Features

### Plugin Information Box
- Displays plugin version (1.0.1)
- Shows plugin description
- Blue info box styling

### Self Test Section

#### Run Self Test Button
- Primary button to execute all diagnostic tests
- Uses WordPress nonce for security
- Redirects back to settings page with results

#### Test Results Display

When tests are run, the page displays three comprehensive test suites:

---

### 1. Plugin Detection Test ✓ PASS

**Purpose:** Verifies that Hypercart Helper is properly loaded and all components are available.

**What it checks:**
- ✓ Hypercart_Time class loaded
- ✓ Hypercart_Logger class loaded
- ✓ HYPERCART_HELPER_VERSION constant defined
- ✓ HYPERCART_HELPER_DIR constant defined
- ✓ HYPERCART_HELPER_FILE constant defined
- Plugin version number
- WordPress version
- PHP version

**Pass Criteria:** All classes exist and all constants are defined

**Fail Scenarios:**
- Missing class files
- Constants not defined
- Plugin not properly activated

---

### 2. Time Handling Test ✓ PASS

**Purpose:** Validates all time-related functions work correctly.

**What it checks:**
- Current UTC timestamp (integer validation)
- UTC formatting (Y-m-d H:i:s format)
- Local timezone formatting
- ISO 8601 format (validates regex pattern)
- Site timezone name and offset
- Weekly slot calculation (0-167 range validation)
- Mock time functionality (set, test, reset)

**Sample Output:**
```
Current UTC Timestamp: 1735502400
UTC Format: 2024-12-29 18:00:00
Local Format: 2024-12-29 10:00:00
ISO 8601: 2024-12-29T18:00:00Z
Site Timezone: America/Los_Angeles (UTC-8)
Weekly Slot: 87 (0-167)
Mock Time Test: ✓ Passed
```

**Pass Criteria:** All methods return valid values in expected formats

**Fail Scenarios:**
- now() returns non-integer or invalid timestamp
- Format methods return empty strings
- ISO 8601 doesn't match expected pattern
- Weekly slot outside 0-167 range
- Mock time doesn't freeze correctly

---

### 3. Log Handling Test ✓ PASS

**Purpose:** Ensures logging system is fully functional and secure.

**What it checks:**
- Log directory exists and is writable
- Security files (.htaccess and index.php) present
- Can write test log entry
- Can read log file
- Test entry appears in log file
- Log file count and total size
- All log levels callable (DEBUG, INFO, WARNING, ERROR)

**Sample Output:**
```
Log Directory: /path/to/wp-content/hypercart-logs
Security Files: ✓ .htaccess | ✓ index.php
Write Test: ✓ Successfully wrote test log entry
Current Log File: hypercart-2024-12-29.log
Read Test: ✓ Successfully read last 5 log entries
Verify Test: ✓ Test entry found in log file
Log Files: 3 file(s), 45.2 KB total
Log Levels: ✓ DEBUG | ✓ INFO | ✓ WARNING | ✓ ERROR
```

**Pass Criteria:** 
- Directory writable
- Security files exist
- Can write and read logs
- All log level methods work

**Fail Scenarios:**
- Cannot create log directory
- Directory not writable
- Missing .htaccess or index.php
- Write operation fails
- Cannot read log file
- Test entry not found in log
- Log level methods not callable

---

## Visual Design

### Color Coding
- **Green border + Green badge:** Test passed ✓
- **Red border + Red badge:** Test failed ✗
- **Blue info box:** Plugin information
- **Gray background:** Test details section

### Layout
```
┌─────────────────────────────────────────────────┐
│ Hypercart Helper Settings                       │
├─────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────┐ │
│ │ ℹ️ Hypercart Helper v1.0.1                   │ │
│ │ Shared utilities for Hypercart plugin suite │ │
│ └─────────────────────────────────────────────┘ │
│                                                 │
│ Self Test                                       │
│ Run diagnostic tests to verify functionality   │
│                                                 │
│ [Run Self Test]                                 │
│                                                 │
│ ┌─────────────────────────────────────────────┐ │
│ │ ✓ All Tests Passed                          │ │
│ │ Hypercart Helper is functioning correctly   │ │
│ └─────────────────────────────────────────────┘ │
│                                                 │
│ ┌─────────────────────────────────────────────┐ │
│ │ Plugin Detection Test          [✓ PASS]     │ │
│ │ All components loaded successfully          │ │
│ │ ┌─────────────────────────────────────────┐ │ │
│ │ │ Classes Loaded: ✓ Hypercart_Time       │ │ │
│ │ │ Plugin Version: 1.0.1                   │ │ │
│ │ └─────────────────────────────────────────┘ │ │
│ └─────────────────────────────────────────────┘ │
│                                                 │
│ [Similar boxes for Time and Log tests...]      │
└─────────────────────────────────────────────────┘
```

## Usage Instructions

1. Navigate to **Settings → Hypercart Helper** in WordPress admin
2. Click **Run Self Test** button
3. Review test results:
   - Green = All good ✓
   - Red = Needs attention ✗
4. If any test fails, check the detailed error message
5. Use the debugging information to troubleshoot issues

## Troubleshooting

### Common Issues

**Log directory not writable:**
- Check file permissions on wp-content directory
- Ensure web server has write access
- Try manually creating /wp-content/hypercart-logs/

**Classes not found:**
- Deactivate and reactivate plugin
- Check for PHP errors in debug log
- Verify all files uploaded correctly

**Time functions failing:**
- Check WordPress timezone settings
- Verify PHP date/time functions available
- Check for conflicting plugins

## Developer Notes

- Tests run on form submission (POST request)
- Results stored in transient for 60 seconds
- Nonce verification prevents CSRF attacks
- All tests use try/catch for error handling
- Test log entries tagged with 'helper-selftest'

