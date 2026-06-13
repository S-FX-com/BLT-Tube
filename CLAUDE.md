# BLT Tube — Development Guidelines

## Versioning (mandatory)

**Every commit that touches any file in this repository must include a version bump — no exceptions, no matter how small the change.**

Both of the following must be updated together and must always match:

1. `blt-tube.php` — plugin header line: `* Version: X.Y.Z`
2. `blt-tube.php` — constant definition: `define( 'BLTT_VERSION', 'X.Y.Z' );`

Use [Semantic Versioning](https://semver.org/):
- **Patch** (`1.1.0 → 1.1.1`): bug fixes, copy changes, minor tweaks
- **Minor** (`1.1.0 → 1.2.0`): new features, backwards-compatible additions
- **Major** (`1.1.0 → 2.0.0`): breaking changes

## Release automation

Merging a PR into `main` automatically creates a GitHub Release tagged `vX.Y.Z` (see `.github/workflows/release.yml`). The workflow skips the release if the tag already exists, so a missing version bump on a PR means **no release is created for that merge**. Always bump the version.
