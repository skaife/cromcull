# Changelog

## v1.3.3 — 2026-09-16

- Added Nextcloud 35 compatibility

## v1.3.2 — 2026-09-14

- Fixed excluded folders not working on external storage mounts (SMB/CIFS)
- Fixed unable to remove folder exclusions on external storage (trashbin wrapper bypass)
- Added root-folder validation to prevent accidental exclusion of the storage root

## v1.3.1 — 2026-09-14

- Fixed excluded folders not reliably appearing after adding, and some folders failing to remove
- Fixed admin settings page taking 10+ seconds to load excluded folders and cache count on instances with many files
- Switched .cromcull_ignore file search from substring match to exact name lookup for dramatically faster queries
- Eliminated redundant file searches when loading excluded folder lists
- Parallelized admin page API calls so cache stats and excluded folders load simultaneously

## v1.3.0 — 2026-09-12

- Persistent hash cache uses Nextcloud's internal edit tracking (etag) to skip re-hashing unchanged files — repeat scans are significantly faster, especially on external storage (NAS/SMB)
- Admin hash cache management panel showing entry count with a full cache clear option
- Per-group "Recheck" button verifies a single duplicate group instantly without a full rescan
- Hide/Un-Hide replaces the old Ignore flow — hidden groups are greyed out with a "Hidden" badge, per-user, keyed on content hash so future copies are automatically hidden
- "Show hidden" toggle to review and un-hide previously hidden groups
- `cromcull:status` occ command for CLI visibility into scan state, group counts, and cache stats
- Scan performance improvements: .cromcull_ignore lookups, mount resolution, and share checks are cached at scan start instead of repeated per file
- Simplified scan flow — single "Scan for Duplicates" button replaces the previous Resume/Restart prompt

## v1.1.0 — 2026-09-03

- Chunked scanning with progress bar and cancel support
- Pre-scan file statistics (file count and match candidates)
- Resume incomplete scans
- User-level scan filters (min/max size, ignored extensions)
- Admin-configurable scan chunk budget (default 50MB)
- Incremental results — review duplicates while scanning continues
- External storage now included in scans
- Parallel delete requests for faster batch operations
- Delete button shows loading state to prevent double-clicks
- Optimized delete verification — only hashes selected files plus one kept copy

## v1.0.1 — 2026-09-03

- Fixed shared and group folder files not showing lock icons until delete attempt
- Files inside shared parent folders are now correctly detected as protected
- Files shared to the current user (incoming shares) are now detected as protected
- Added protection checks for email, federated, and Talk shares

## v1.0.0 — 2026-08-29

- Initial release
