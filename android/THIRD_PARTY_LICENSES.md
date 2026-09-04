# Third-Party License Obligations — BoothPOS Android Installer

Tracked here per `research.md` R2's explicit risk flag and `tasks.md`
T007/T034 — **must be kept accurate before any real-world distribution**
of a built APK, not left as a placeholder.

## MariaDB (embedded database engine)

- **License**: GPLv2 (the MariaDB Server itself).
- **Why it's here**: research.md R2 — MySQL publishes no official
  Android/ARM builds; MariaDB is the wire/DDL-compatible substitute that
  lets every existing migration/model/query run unmodified.
- **Obligation**: redistributing a GPLv2 binary inside a distributed APK
  carries source-availability and attribution obligations. **This has not
  been resolved by this scaffold** — whoever ships a built APK to an
  actual store must either (a) make the exact MariaDB source
  corresponding to the bundled binary available (e.g., a link to the
  upstream release tag used), or (b) obtain a distribution arrangement
  that satisfies GPLv2's terms some other way. Do not distribute a built
  APK until this line item is checked off for real.

## PHP runtime binary

- **License**: PHP License (BSD-style, permissive) — typically requires
  only a copyright/license notice be retained, no source-availability
  obligation comparable to MariaDB's GPLv2. Confirm the exact license
  text shipped with whichever prebuilt/self-built PHP binary is
  ultimately sourced (research.md's "sourcing strategy is an open
  implementation decision," android/README.md).

## `mysqldump` / `mysql` client binaries

- **License**: Same GPLv2 family as MariaDB Server if sourced from a
  MariaDB client package (the expected path, per android/README.md) —
  same obligation as above, not a separate one.

## `tar` (or busybox equivalent)

- **License**: Depends entirely on which specific build is sourced (GNU
  tar is GPLv3; BusyBox is GPLv2; a from-scratch minimal implementation
  could be written under any license) — **record the actual choice here
  once made**, this table entry is a placeholder until then.

## Existing application dependencies (unchanged)

Every dependency in `composer.json`/`package.json` is unchanged by this
feature and carries whatever license it already did on desktop — this
feature adds no new PHP/JS dependency, only new Android-native/runtime
binaries (the table above). Not re-audited here since nothing changed.
