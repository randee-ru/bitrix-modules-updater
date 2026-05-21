# Randee Update (`randee.update`)

Marketplace-backed update module for Bitrix projects.

## What this module is for

`randee.update` connects a Bitrix site to the Randee marketplace service (`updates.c0l.ru`).
It lets the site:

- activate a license key;
- load the catalog of available products;
- download release packages from the marketplace;
- validate package structure before installation;
- install only the files that belong to the package payload;
- keep the last install, download, and rollback state;
- roll back the last successful installation when needed.

This module is the client-side part of the system. The marketplace server stores product metadata,
release metadata, and access rules. The Bitrix module only asks the server what is available and
then installs the package on the client site.

## Who this repository is for

This repository is meant for:

- a developer who wants to understand how `randee.update` works;
- a Bitrix integrator who needs to install or troubleshoot the module;
- a maintainer who wants to build a new release ZIP;
- a beginner who only needs a simple path from installation to update.

If you are new to the project, start with [docs/00-start-here.md](docs/00-start-here.md).

## Quick start for beginners

1. Install the module in Bitrix.
2. Open the marketplace page in the admin panel.
3. Enter the marketplace URL, license key, and site UID.
4. Save settings.
5. Activate the license.
6. Open a product card.
7. Download the package.
8. Run install.

For a more detailed walkthrough, read [docs/01-beginner-guide.md](docs/01-beginner-guide.md).

## Admin entrypoint

- marketplace page: `/bitrix/admin/randee_update_marketplace.php`
- legacy entrypoint: `/bitrix/admin/randee_update.php` redirects to the marketplace page

## Package contract

Packages must follow the Randee package contract:

- package format: ZIP;
- required manifest: `package.json` in the root of the archive;
- installation root: `payload/`;
- required fields: `format`, `format_version`, `product_id`, `type`, `version`, `channel`, `release_tag`, `build_number`, `install_root`, `paths`;
- channel set: `stable`, `beta`, `hotfix`, `dev`.

The archive is validated before install. If the manifest is missing or invalid, the module stops
and reports the reason.

## Repository structure

- `admin/` - Bitrix admin entrypoints.
- `include.php` - autoload map for module classes.
- `install/` - installer, version, language files.
- `lib/` - core runtime classes.
- `docs/` - beginner and technical documentation.
- `LICENSE` - project license.

## Main runtime classes

- `MarketplaceClient` - talks to the marketplace API.
- `LicenseManager` - stores and checks license state.
- `PackageDownloader` - downloads packages to a writable temp directory.
- `PackageValidator` - checks archive layout and manifest fields.
- `PackageInstaller` - unpacks and installs files from `payload/`.
- `RollbackManager` - keeps rollback state.
- `UpdateLogger` - records install and download events.
- `VersionComparator` - compares installed and available versions.

## Installation flow

1. Bitrix registers the module.
2. The admin page copies its UI files into `/bitrix/admin`.
3. The admin fills in marketplace settings.
4. The module calls the marketplace API.
5. The catalog is loaded.
6. A package is downloaded into a writable temp folder.
7. The package is validated.
8. Files from `payload/` are installed into the Bitrix site.
9. The installed version is saved locally.

## Current state

- module installation and admin integration are live;
- marketplace catalog and license activation are wired to `updates.c0l.ru`;
- package validation and install flow are enforced in code;
- rollback state is stored locally for the last successful install.

## Documentation index

- [docs/00-start-here.md](docs/00-start-here.md) - entry point for newcomers.
- [docs/01-beginner-guide.md](docs/01-beginner-guide.md) - step-by-step beginner guide.
- [docs/02-package-format.md](docs/02-package-format.md) - ZIP and `package.json` contract.
- [docs/03-installation-and-troubleshooting.md](docs/03-installation-and-troubleshooting.md) - install, update, errors, and rollback.
