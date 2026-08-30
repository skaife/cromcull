# CromCull

Find and manage byte-identical duplicate files in Nextcloud.

## How it works

CromCull uses a three-stage detection funnel to efficiently find exact duplicates:

1. **Size-bucket pre-filter** — queries Nextcloud's existing file cache to group files by size (no re-scanning required)
2. **Partial hash** — reads the first 64 KB of each candidate and computes a SHA-256 hash to cheaply eliminate non-matches
3. **Full-file SHA-256** — confirms exact duplicates with a complete file hash

## Safety

- CromCull never deletes anything automatically
- Every deletion is an explicit, per-file human decision
- All deletions go through Nextcloud's own trash bin
- Group folders and shared files are blocked from deletion
- A permanent CSV audit log records every action

## Features

- Configurable minimum/maximum file size filters
- Extension ignore list to skip file types you don't care about
- Folder exclusions via `.cromcull_ignore` marker files that travel with renamed/moved folders
- Review UI with per-file external links for side-by-side comparison
- Batch delete across multiple groups with confirmation modal
- Live re-verification before every delete to catch stale data

## Requirements

- Nextcloud 33 or 34

## License

AGPL-3.0-or-later — see [COPYING](COPYING) for the full text.
