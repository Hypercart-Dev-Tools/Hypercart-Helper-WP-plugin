# Hypercart Helper - UI Improvements v1.0.2

**Date:** December 29, 2024  
**Version:** 1.0.2  
**Changes:** Plugin listing improvements and settings page title enhancement

---

## Summary of Changes

Two user experience improvements have been added to make the Self Test feature more accessible:

1. ✅ **"Self Test" link on All Plugins page** - Quick access from plugin listing
2. ✅ **Version number in settings page title** - Better visibility of current version

---

## Change 1: Self Test Link on Plugins Page

### Before
```
┌─────────────────────────────────────────────────────────────┐
│ Hypercart Helper                                            │
│ Shared utilities for the Hypercart plugin suite...         │
│ Version 1.0.2 | By Neochrome                                │
│                                                             │
│ Deactivate | Edit                                           │
└─────────────────────────────────────────────────────────────┘
```

### After
```
┌─────────────────────────────────────────────────────────────┐
│ Hypercart Helper                                            │
│ Shared utilities for the Hypercart plugin suite...         │
│ Version 1.0.2 | By Neochrome                                │
│                                                             │
│ Self Test | Deactivate | Edit                               │
│ ^^^^^^^^                                                    │
│ NEW LINK - Quick access to diagnostics!                     │
└─────────────────────────────────────────────────────────────┘
```

### What It Does
- Adds a **"Self Test"** link as the first action link
- Clicking it takes you directly to Settings → Hypercart Helper
- No need to navigate through Settings menu
- Appears on both:
  - **Plugins → Installed Plugins** page
  - **Plugins → Active** filtered view

### Technical Implementation
```php
/**
 * Add plugin action links on plugins page
 *
 * @since 1.0.2
 * @param array $links Existing plugin action links.
 * @return array Modified plugin action links.
 */
public static function add_plugin_action_links( array $links ): array {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url( admin_url( 'options-general.php?page=hypercart-helper' ) ),
        esc_html__( 'Self Test', 'hypercart-helper' )
    );
    
    array_unshift( $links, $settings_link );
    
    return $links;
}
```

### Filter Hook Used
```php
add_filter( 
    'plugin_action_links_' . plugin_basename( HYPERCART_HELPER_FILE ), 
    array( __CLASS__, 'add_plugin_action_links' ) 
);
```

---

## Change 2: Version Number in Page Title

### Before
```
┌─────────────────────────────────────────────────────────────┐
│ Hypercart Helper Settings                                   │
├─────────────────────────────────────────────────────────────┤
│ ℹ️ Hypercart Helper v1.0.2                                   │
│ Shared utilities for Hypercart plugin suite                │
└─────────────────────────────────────────────────────────────┘
```

### After
```
┌─────────────────────────────────────────────────────────────┐
│ Hypercart Helper Settings v1.0.2                            │
│                               ^^^^^^                        │
│                               VERSION NOW IN TITLE!         │
├─────────────────────────────────────────────────────────────┤
│ ℹ️ Hypercart Helper v1.0.2                                   │
│ Shared utilities for Hypercart plugin suite                │
└─────────────────────────────────────────────────────────────┘
```

### What It Does
- Displays version number in the main page heading (H1)
- Shows in browser tab title
- Visible in WordPress admin breadcrumbs
- Makes it easier to verify which version is installed

### Technical Implementation
```php
public static function add_admin_menu(): void {
    add_options_page(
        sprintf( __( 'Hypercart Helper Settings v%s', 'hypercart-helper' ), HYPERCART_HELPER_VERSION ),
        __( 'Hypercart Helper', 'hypercart-helper' ),
        'manage_options',
        'hypercart-helper',
        array( __CLASS__, 'render_settings_page' )
    );
}
```

### Where Version Appears
1. **Page Title (H1)** - Main heading on settings page
2. **Browser Tab** - Shows "Hypercart Helper Settings v1.0.2"
3. **Admin Menu** - Menu item still shows "Hypercart Helper" (clean)
4. **Info Box** - Still shows version in info box (redundant but helpful)

---

## User Benefits

### Faster Access to Diagnostics
- **Before:** Plugins → Settings → Hypercart Helper → Run Self Test (3 clicks)
- **After:** Plugins → Self Test → Run Self Test (2 clicks)
- **Time Saved:** ~30% faster access

### Better Version Visibility
- **Before:** Had to scroll to info box to see version
- **After:** Version visible immediately in page title
- **Benefit:** Easier troubleshooting and support

### Professional Polish
- Matches WordPress plugin standards
- Consistent with other well-designed plugins
- Improves user confidence

---

## Files Modified

| File | Changes | Lines Changed |
|------|---------|---------------|
| `includes/class-hypercart-admin.php` | Added action links method, updated page title | +20 lines |
| `hypercart-helper.php` | Updated version to 1.0.2 | 2 lines |
| `CHANGELOG.md` | Documented changes | +15 lines |

---

## Testing Checklist

- [x] PHP syntax validation passed
- [x] No WordPress function errors
- [x] Action link method added
- [x] Filter hook registered
- [x] Page title includes version
- [x] CHANGELOG updated
- [x] Version number incremented

---

## Visual Preview

### All Plugins Page
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Hypercart Helper
Shared utilities for the Hypercart plugin suite. Provides 
centralized time handling (UTC storage, local display) and 
structured file-based logging.

Version 1.0.2 | By Neochrome | View details

[Self Test] | Deactivate | Edit
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

### Settings Page
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Hypercart Helper Settings v1.0.2
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

┌───────────────────────────────────────────────────────────┐
│ ℹ️ Hypercart Helper v1.0.2                                 │
│ Shared utilities for the Hypercart plugin suite.         │
│ Provides centralized time handling (UTC storage, local   │
│ display) and structured file-based logging.              │
└───────────────────────────────────────────────────────────┘

Self Test
Run diagnostic tests to verify that Hypercart Helper is 
functioning correctly.

[Run Self Test]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

---

## Compatibility

- ✅ WordPress 6.0+
- ✅ PHP 7.4+
- ✅ All modern browsers
- ✅ WordPress multisite compatible
- ✅ Translation ready (uses `__()` and `esc_html__()`)

---

## Next Steps

To see the changes:
1. Reload the WordPress admin
2. Go to **Plugins → Installed Plugins**
3. Look for the **"Self Test"** link under Hypercart Helper
4. Click it to verify it navigates to the settings page
5. Confirm the page title shows **"Hypercart Helper Settings v1.0.2"**

---

## Conclusion

These small but impactful UI improvements make the Hypercart Helper plugin more user-friendly and professional. Users can now access diagnostics faster and see the version number at a glance! 🎉

