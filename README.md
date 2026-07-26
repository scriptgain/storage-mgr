# StorageMGR

**S3-compatible object storage you run yourself.** By
[ScriptGain](https://scriptgain.com).

**[Try the live demo →](https://storage-demo.scriptgain.com)** — no signup required.

> **Beta.** StorageMGR is not currently offered for sale. It runs in production
> on ScriptGain infrastructure and the demo is live, but there is no purchase plan
> on the storefront yet.

## Who it's for

Anyone who wants an S3 endpoint on their own hardware: application backups,
customer file storage, media origin, log archive, or a target for backup software
that only speaks S3.

## What it does

**Speaks real S3**
Buckets and objects over the S3 REST API with **SigV4 request signing**, both
path-style and virtual-host-style addressing, and multipart upload for large
objects. Verified against the **official AWS CLI** and MinIO's `mc` client, not
just against itself.

**Access keys that behave like AWS**
Access key IDs and secrets, with the secret shown exactly once at creation.
JSON access policies enforced per key, so a key can be scoped to one bucket or to
read-only.

**Data protection built in**
Object versioning, Object Lock for write-once retention, and lifecycle rules that
expire or transition objects on a schedule. **AES-256 encryption at rest.**

**Usage you can see**
Per-bucket size and object counts, recalculated on a schedule, so you know what is
actually stored rather than what someone thinks is stored.

**Run it like production**
Users and roles, two-factor authentication, an IP firewall with an escape hatch,
API tokens, a full audit log, database backups, host and SSL settings, and
in-place signed updates.

## Current state

**Version 1.1.3.** The data plane is real and tested: uploads are stored,
retrieved, versioned, locked, and expired as documented, and the protocol surface
has been exercised with third-party S3 clients.

Worth knowing, because it is the kind of thing a reader should be able to trust a
README about: **an earlier build recorded metadata and discarded the file body.**
That is fixed and the current version genuinely stores and serves object data.

## Deployment note

**Never put an S3 endpoint behind a proxy that rewrites headers.** Cloudflare's
proxy strips `ETag`, which breaks multipart uploads and any client that verifies
what it uploaded. Point DNS straight at the origin — grey cloud, no proxy — and
terminate TLS on the host.

## Install

Point a fresh Debian or Ubuntu server at your domain and run, as root:

```
curl -fsSL https://install.scriptgain.com | sudo bash -s -- storage-mgr DOMAIN=storage.example.com SSL=1 EMAIL=you@example.com
```

Then open `https://your.domain/setup` to create the first account and enter your
licence key. Create a bucket and an access key, and point your S3 client at the
endpoint.

## Where things live

| Surface | Path |
| --- | --- |
| Console | `/` |
| First-run setup | `/setup` |
| S3 API | the endpoint host you configure |

## Running it

Buckets, keys, policies, lifecycle rules, and every operator setting are managed
in the console rather than in files on the server.

Maintenance tasks from the command line:

| Command | What it does |
| --- | --- |
| `php artisan storage:maintenance` | Recalculates bucket usage, disables stale keys, prunes the audit log. |
| `php artisan storage:lifecycle` | Applies lifecycle rules — expiries and transitions. |
| `php artisan license:check-online` | Re-validates your licence. |
| `php artisan app:update` | Applies a signed release. |
| `php artisan db-backup:run` | Backs up the database. |
| `php artisan firewall:clear` | Gets you back in if an IP rule locks you out. |

## Requirements

A Linux server with PHP 8.3 and MySQL or MariaDB, and disk. The database holds
metadata; object bodies live on the filesystem you point it at, so size that
volume for your data and keep it on something you can grow.

## Licensing

Validated against `https://scriptgain.com/v1`.
