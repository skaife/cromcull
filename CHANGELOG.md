# Changelog

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
