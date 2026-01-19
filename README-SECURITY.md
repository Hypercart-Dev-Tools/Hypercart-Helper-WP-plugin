# Securing Hypercart Helper Logs

This document provides guidance on how to secure your log files, which may contain operational data about your site. By default, Hypercart Helper attempts to create logs inside `wp-content/hypercart-logs/` and protect it from casual browsing. However, server configurations vary, and additional steps are recommended to ensure this data is not publicly accessible.

Our target users are system administrators who are comfortable with server configurations.

---

## 1. The Recommended Solution: Move the Log Directory

The most secure method is to move the log directory completely outside of your website's public web root. This makes it impossible for the files to be accessed via a web browser.

You can do this by adding a small code snippet to your **`wp-config.php`** file. This is the most reliable solution and works on all hosting platforms.

```php
/**
 * Move Hypercart logs to a non-public directory.
 * This directory should be parallel to your web root (e.g., public_html, www).
 */
add_filter( 'hypercart_log_dir', function( $log_dir ) {
    // Navigates up one level from /wp-content/ to the directory containing the WP installation.
    // Creates a secure 'hypercart-logs-private' directory there.
    return WP_CONTENT_DIR . '/../hypercart-logs-private';
} );
```

This is the preferred, set-and-forget solution.

---

## 2. Alternative: Block Web Access

If you cannot move the directory, you can block access to it at the server level. This is a good alternative but relies on your specific web server configuration.

Find the instructions for your server type below.

### For Apache / LiteSpeed Servers

Most shared hosts (like Dreamhost, SiteGround, etc.) use Apache. You can add the following to the `.htaccess` file located in your log directory (`/wp-content/hypercart-logs/.htaccess`). The plugin attempts to create this, but you should verify its contents.

For maximum security, ensure the file contains these rules:

```apache
# Block all web access to this directory
<IfModule mod_authz_core>
    Require all denied
</IfModule>
<IfModule !mod_authz_core>
    Deny from all
</IfModule>

# Also prevent execution of any PHP scripts that might be uploaded
<Files *.php>
    deny from all
</Files>
```

### For Nginx Servers

Nginx does not use `.htaccess` files. You must add a rule to your site's Nginx configuration file. This is common on cloud servers, VPS environments, and many managed WordPress hosts like **WP Engine**, Kinsta, and Flywheel.

Add the following `location` block to your server's configuration. This will block any request that tries to access the `hypercart-logs` directory.

```nginx
# Block access to the Hypercart Helper log directory
location /wp-content/hypercart-logs/ {
    deny all;
    # Or, for a less revealing error: return 404;
}
```

**For users on managed hosts (WP Engine, etc.):** You likely do not have direct access to your Nginx configuration. We strongly recommend using the **`wp-config.php`** method above. It is the most compatible solution. If you must use the server-level block, you will need to contact your host's support team and ask them to add the Nginx rule for you.

---

## 3. How to Verify

After implementing one of the solutions above, you can verify it's working:

1.  Find your site's log directory URL. It will be something like: `http://your-site.com/wp-content/hypercart-logs/`
2.  Try to visit that URL in your browser.
3.  You should see an "Access Denied," "403 Forbidden," or "404 Not Found" error. You should **not** see a blank page or a directory listing.

If you have chosen the recommended solution of moving the directory, the verification URL above should result in a 404 Not Found error from WordPress itself, as the path no longer exists within the web root.
