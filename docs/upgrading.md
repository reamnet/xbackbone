---
layout: default
title: Upgrading
nav_order: 4
---

# Upgrading

The system updates can be applied via the web interface by an administrator, or manually via CLI.

## Self-update (since v2.5)
+ Navigate to the system page as administrator.
+ Click the check for update button, and finally the upgrade button.
+ Wait until the browser redirect to the install page.
+ Click the update button.
+ Done.


## Manual update
+ Download and extract the release zip to your document root, overwriting any file.
+ Navigate to the `/install` path (es: `http://example.com/` -> `http://example.com/install/`)
+ Click the update button.
+ Done.

## CLI update
If, for whatever reason, the web UI is not accessible, you can upgrade from CLI:
+ Download and extract the release zip to your document root, overwriting any file.
+ Run the command `php\migrate`.
+ Run the command `php\clean`.
+ Done.

### Pre-release channel

From the system page, you can also choose to check from beta/RC releases, these are NOT considered stable enough for every day use, but only for testing purposes, **take a backup before upgrading to these versions**.
## 3.8.2 QoL container (LinuxServer-compatible)

For the complete host commands, configuration and rollback procedure, see the
[LinuxServer 3.8.2 migration guide](linuxserver-migration.md).

This branch targets **3.8.2**, not the Laravel rewrite on master. It preserves the
legacy database, passwords, uploader tokens, user codes, resource codes and
storage object keys. Schema updates only add metadata, SSO identities and thumbnail
jobs. Startup does not recalculate quotas or delete uploads.

Use compose.yaml as a reference when editing your existing Compose service. Keep
existing port mappings, proxy labels/networks, PUID, PGID, URL and both mounts:

    volumes:
      - /opt/xbb/config:/config
      - /mnt/data/:/mnt/data

1. Stop the old container and take a restorable backup of /opt/xbb/config and your
   upload storage. Back up MySQL separately if used. Keep the old image.
2. Copy .env.example to .env; enter your existing URL and numeric PUID/PGID.
   Enter SMTP and Authentik values locally. Never commit .env.
3. In the existing service replace its image with xbackbone:3.8.2-qol, add
   build: . and copy desired environment variables from the example.
   Keep your existing mounts and networking.
4. Run docker compose build --pull xbackbone, then
   docker compose up -d --no-deps xbackbone.
5. Check docker compose logs xbackbone, sign in with an existing account, open an
   old link and verify existing files. Test a new upload and its download.
   Automatic pre-migration SQLite backups go to /opt/xbb/config/backups/;
   they supplement your stopped-server backup.

The image requires an existing /config/www/xbackbone/config.php and refuses to
create a database when the configured SQLite file is missing. Its original
database and storage paths are retained, including absolute /mnt/data paths.
Image code and cache files are replaced; persisted themes and sessions remain.
The legacy in-app updater must not be used on this custom image; rebuild this
branch for upgrades. The image does not execute old /config nginx customization
snippets: reapply any required custom access controls to docker/nginx.conf before
deployment. Existing LinuxServer certificate/key files at /config/keys/cert.crt
and /config/keys/cert.key enable port 443; otherwise terminate HTTPS at your reverse
proxy. Alternate paths can be passed as SSL_CERT_PATH and SSL_KEY_PATH.

To roll back, stop the new container, restore the stopped-server database/config
backup and restart the old image with the original mounts. Keep uploads created
after the backup separately; an older database does not register them.

### Mail

Set SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_ADDRESS and
SMTP_FROM_NAME in .env. SMTP_SECURITY=tls requires STARTTLS (usually 587); ssl
uses implicit TLS (usually 465). none is only for a trusted local test server.
Certificate verification stays enabled. Without SMTP_HOST, the existing sendmail
path remains. Registration and password recovery use this transport.
Test with docker compose exec xbackbone php bin/mail-test you@example.com.
Successful SMTP submission does not prove inbox delivery; sender authorization,
DNS and spam policies belong to the mail provider.

### Authentik

Create an OAuth2/OpenID provider and application in Authentik, using a confidential
client with Authorization Code and PKCE. Register the exact callback URI
https://YOUR-XBACKBONE/sso/callback, including any existing base path.
Use openid, profile and email scopes. Set these environment variables:

    OIDC_ENABLED=true
    OIDC_ISSUER=https://YOUR-AUTHENTIK/application/o/YOUR-APP-SLUG/
    OIDC_CLIENT_ID=your-client-id
    OIDC_CLIENT_SECRET=your-client-secret
    OIDC_AUTO_REGISTER=false

Recreate the container after changes. Existing users sign in locally and use
**Link Authentik** in their profile, confirming their password. Accounts bind to
issuer and subject, never automatically by email. Local login stays available.
OIDC_AUTO_REGISTER=true allows new non-admin accounts only with a verified,
unused email (legacy limit: 30 bytes). Authentik groups do not grant admin rights.
Signature, issuer, audience, nonce, state and PKCE checks stay enabled.
Real-provider login requires your own issuer and credentials on your server.

### Uploads, thumbnails and organization

Browser uploads use sequential 8 MiB chunks with retries and a default per-file
limit of 20 GiB (UPLOAD_MAX_BYTES). Direct ShareX/API multipart uploads keep the
existing endpoint; PHP permits 20 GiB and nginx permits 21 GiB. Raising the file
limit above 20 GiB also requires raising PHP/nginx limits. Upstream proxies can
still impose their own request limits/timeouts. Ensure temporary disk space for
chunks plus the final copy (up to twice the file size). Abandoned progress expires
after 24 hours and is pruned in the background. Chunk retry support does not imply
browser-restart resumability.

The supervised FFmpeg worker generates thumbnails and backfills existing videos.
Originals stay unchanged. Failed jobs stop after three attempts; run
php bin/previews-retry to reset failed jobs.

The pencil button edits filename (keeping its extension), game, description,
recorded date and comma-separated tags. Tags are trimmed, case-normalized and
deduplicated, with a 20-tag limit. Renaming preserves stored paths and old links.
Search matches all entered words across filenames, codes, games, descriptions,
tags and dates; combine video and upload-date filters to narrow results.
