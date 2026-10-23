# Migrate an existing LinuxServer XBackBone 3.8.2 installation

This guide is for `lscr.io/linuxserver/xbackbone:latest` with these existing mounts:

```yaml
volumes:
  - /opt/xbb/config:/config
  - /mnt/data/:/mnt/data
```

The new image uses your existing database, accounts, upload tokens, storage paths
and links. Startup applies additive schema migrations. It requires the existing
`/opt/xbb/config/www/xbackbone/config.php`; do not run the installer or create a new
database. This is a custom image built from the repository, not an image published
at `lscr.io` or GHCR.

## 1. Download the source and configure it

On the Docker host:

```sh
sudo git clone --branch improvements/3.8.2 --single-branch \
  https://github.com/reamnet/xbackbone.git /opt/xbb/qol-source
cd /opt/xbb/qol-source
sudo cp .env.example .env
sudo chmod 600 .env
sudoedit .env
```

Set `APP_URL` to your current public URL, including a base path if you use one.
Keep `PUID`, `PGID`, `TZ`, and host ports the same as the old container. Enter
SMTP credentials and, optionally, Authentik settings below. Never commit `.env`.
If a value contains `$` or `#`, use single quotes around the value in `.env`.

Find your existing Compose directory and service name. The commands below assume
the service is named `xbackbone`; substitute its actual name if different. Keep a
copy of the old Compose files and environment file in a private backup directory.
Do not start the repository's Compose file alongside your existing service: both
would mount the same database and storage.

In your **existing** service, replace the image and add a build context:

```yaml
services:
  xbackbone:
    image: xbackbone:3.8.2-qol
    build:
      context: /opt/xbb/qol-source
```

Copy the `environment` entries from the repository's `compose.yaml` into that
service, retaining any additional settings your deployment needs. Keep your
existing two mounts, host ports, reverse proxy labels, networks and dependencies.
In particular, keep the network connection to MySQL if your installation uses it.
The sample Compose ports are only defaults; they need not match your installation.

The image reuses `/config/keys/cert.crt` and `cert.key` for container HTTPS if
present. Otherwise use your existing HTTPS reverse proxy to reach port 80. Old
LinuxServer `/config/nginx` snippets are not loaded; reapply any custom access
controls to `docker/nginx.conf` before building.

## 2. Build before stopping the old service

Run these commands from your **existing Compose directory**. Use the same
`--env-file` option for subsequent Compose commands so interpolation is consistent:

```sh
sudo docker compose --env-file /opt/xbb/qol-source/.env config --quiet
sudo docker compose --env-file /opt/xbb/qol-source/.env build --pull xbackbone
```

If your old Compose file needs other variables, add those to this private `.env`
file too, preserving their values. A build failure leaves the running old
container in place. The build downloads PHP 8.5, Composer and npm dependencies,
and FFmpeg; no host PHP or npm installation is needed.

Keep the exact old image for rollback before replacing its container:

```sh
sudo docker image tag \
  "$(sudo docker inspect --format '{{.Image}}' \
    "$(sudo docker compose --env-file /opt/xbb/qol-source/.env ps -q xbackbone)")" \
  xbackbone:pre-qol-backup
```

## 3. Stop, back up, then switch

Pause uploads and stop the old service:

```sh
sudo docker compose --env-file /opt/xbb/qol-source/.env stop xbackbone
```

Take a restorable backup of `/opt/xbb/config` **while the service is stopped**.
For example, save a compressed archive on a backup filesystem with enough space:

```sh
sudo tar --acls --xattrs --numeric-owner -czpf /YOUR-BACKUP-DISK/xbb-config-pre-qol.tar.gz \
  -C /opt/xbb config
```

Replace `/YOUR-BACKUP-DISK` with a real backup directory. Back up or snapshot the
upload storage in `/mnt/data/` as well. If your configuration points to other local
or remote storage, protect that storage too. For MySQL, take a separate consistent
database backup; the config archive does not include an external database. Verify
the backups are usable before proceeding.

Start the replacement using the same service and mounts:

```sh
sudo docker compose --env-file /opt/xbb/qol-source/.env up -d --no-deps xbackbone
sudo docker compose --env-file /opt/xbb/qol-source/.env logs --tail=100 xbackbone
```

Expect `Schema migrations complete`. SQLite receives an additional automatic
backup in `/opt/xbb/config/backups/` before pending schema migrations; this is a
supplement to your own stopped-server backup. No reset or quota recalculation runs.
Never run the legacy `bin/migrate` maintenance command for this upgrade; it can
remove records when storage is unavailable. Rebuild the custom image for updates
instead of using XBackBone's in-app updater.

## 4. Verify before reopening uploads

- Sign in with an existing local account and check existing upload history.
- Open an old public link and download its original file.
- Test your existing ShareX/uploader token.
- Upload a video, play and seek it, and wait for its thumbnail.
- Upload a file larger than 2 GiB if that is part of your normal use.
- Rename a test upload, add a game and tags, then find it with search. Its old
  stored URL should continue to work.

Browser uploads use sequential 8 MiB chunks; the default per-file cap is 20 GiB.
`UPLOAD_MAX_BYTES` changes that cap, but going above 20 GiB also requires raising
`docker/php.ini` and `docker/nginx.conf` limits and rebuilding. Allow temporary
disk space for chunks and the final copy, up to twice the uploaded file size.
Check request limits and timeouts in your reverse proxy too; direct API uploads
remain a single multipart request. Thumbnail backfill runs in the background and
can take time for a large library.

## 5. Set up SMTP, Authentik and branding

For authenticated mail, set these in `.env` using your mail provider's values:

```dotenv
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_SECURITY=tls
SMTP_USERNAME='your-smtp-user'
SMTP_PASSWORD='your-smtp-password'
SMTP_FROM_ADDRESS=files@example.com
SMTP_FROM_NAME=XBackBone
```

Use `tls` for STARTTLS, usually port 587, or `ssl` for implicit TLS, usually 465.
For a personal mail server, use its SMTP submission hostname, port and permitted
sender address. Leave both authentication fields empty only if your server is
configured to accept mail from this host without authentication. Keep TLS
certificate verification enabled.

**The SMTP variables must be present in the running container.** An `.env` file
and Compose's `--env-file` supply values for `${...}` interpolation; they do not
automatically export those values into the service. Confirm the actual service
has the `SMTP_*` environment entries shown in `compose.yaml`. If Portainer
manages the service, update that same stack's environment entries and variables.
Restarting an existing container does not refresh its environment; recreate it.

Recreate after changing environment settings, then test:

```sh
sudo docker compose --env-file /opt/xbb/qol-source/.env up -d --no-deps xbackbone
sudo docker compose --env-file /opt/xbb/qol-source/.env exec xbackbone \
  php bin/mail-test you@example.com
```

If no mail arrives, first check the loaded configuration without sending anything:

```sh
sudo docker exec xbackbone php bin/mail-test --check
```

This reports the hostname, port, encryption mode and whether credentials are set;
it never prints the username or password. `SMTP_HOST is not configured inside the
container` means you need to correct the Compose/Portainer environment mapping and
recreate the service. The container has no local sendmail daemon. The test now
exits with an error on failure instead of reporting false success. An SMTP
acceptance response confirms submission only; inspect your mail server's queue,
logs and recipient spam folder if the message was accepted but not delivered.

In Authentik, create an OAuth2/OpenID provider and an application. Use a
confidential client, Authorization Code flow with PKCE, and `openid`, `profile`,
and `email` scopes. Register the exact redirect URI
`https://YOUR-XBACKBONE/sso/callback`, including your base path if applicable.
Set:

```dotenv
OIDC_ENABLED=true
OIDC_ISSUER=https://YOUR-AUTHENTIK/application/o/YOUR-APP-SLUG/
OIDC_CLIENT_ID='your-client-id'
OIDC_CLIENT_SECRET='your-client-secret'
OIDC_AUTO_REGISTER=false
```

Recreate the service as above. Existing users first sign in locally, open their
profile, and select **Link Authentik**, confirming their password. Accounts are
linked by issuer and subject, not automatically by matching email. Keep a local
admin login available. Optional `OIDC_AUTO_REGISTER=true` permits new non-admin
accounts only with verified, unused emails (the legacy email limit is 30 bytes).
Authentik groups do not automatically grant XBackBone administrator access.

For branding, sign in as an administrator and open system settings to set the
site name, HTTPS logo URL and footer. Use the pencil button on an upload to edit
its filename, game, description, recorded date and tags. Search includes these
fields and upload dates, with video and date filters.

## Rollback

Stop the new service. Preserve any uploads created since the backup separately.
Restore `/opt/xbb/config` and, if used, the external database from the same
stopped-server backup. Restore the original Compose configuration, set its image
to `xbackbone:pre-qol-backup`, and start it with its original environment and
mounts. Restore storage if needed; files created after the database backup will
not appear in the restored database. Do not run old and new containers together
against the same database.

## Validation and limits

Local checks passed: 35 tests / 84 assertions, PHPStan, Composer validation and
asset build. HTTP tests covered a 2,147,483,649-byte upload, a range request beyond
2 GiB, chunk retries, rename, tags and search. An earlier container upgrade test
preserved legacy login, token and media URLs. The final Docker rebuild was blocked
by HTTP 503 errors in this cloud environment's outbound proxy, so build and verify
the final image before switching your service. Live Authentik sign-in still needs
verification with your provider. No production server was modified here.
