# Nginx defense-in-depth: no script execution from upload directories

Recommendation only - nothing here was applied to Forge. Apply in the site's Nginx config (Forge: Site > Nginx Configuration)
ABOVE the generic `location ~ \.php$` block, then `nginx -t && reload`. Test on staging first.

Web-reachable upload directories (see `deploy/forge/verify-uploads.sh`):
`images/websites images/business images/logo images/branding mms voice senderid_docs`.
New sender-ID documents no longer go to `senderid_docs`; legacy files there remain until
`php artisan senderid:migrate-documents --apply` is run (dry-run by default).

```nginx
# 1. Never hand anything under an upload directory to PHP, whatever its extension.
location ~* ^/(images/(websites|business|logo|branding)|mms|voice|senderid_docs)/.*\.(php\d?|phtml|phar|pht|phps|inc)$ {
    return 404;
}

# 2. Serve everything else there as inert static content only.
location ~* ^/(images/(websites|business|logo|branding)|mms|voice|senderid_docs)/ {
    add_header X-Content-Type-Options "nosniff" always;
    add_header Content-Security-Policy "default-src 'none'; sandbox" always;
    try_files $uri =404;            # no fall-through to index.php
}

# 3. After the migration command has been applied on that environment, stop serving legacy documents publicly:
# location ^~ /senderid_docs/ { return 404; }
```

Also: PHP-FPM `security.limit_extensions = .php`; upload dirs 0755 (`Tool::uploadImage` creates 0777); never symlink
`storage/app` under `public/`. Verify with a harmless probe: put a file `probe.php` containing `<?php echo 1;` in `public/mms`,
expect 404, delete it.
