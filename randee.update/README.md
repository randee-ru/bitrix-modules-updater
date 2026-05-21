# Randee Update (`randee.update`)

Marketplace-backed update module for Bitrix projects.

## What it does

- connects Bitrix sites to `updates.c0l.ru`;
- activates licenses and loads the product catalog;
- downloads release packages only through the marketplace backend;
- validates `Randee Package` archives before install;
- installs only the `payload/` tree;
- records the last download, install, and rollback state;
- supports rollback from stored backup state;
- exposes the marketplace admin page in Bitrix.

## Admin entrypoint

- marketplace page: `/bitrix/admin/randee_update_marketplace.php`
- legacy entrypoint: `/bitrix/admin/randee_update.php` now redirects to the marketplace page

## Package contract

- package format: ZIP
- required manifest: `package.json`
- installation root: `payload/`
- required fields: `format`, `format_version`, `product_id`, `type`, `version`, `channel`, `release_tag`, `build_number`, `install_root`, `paths`
- channel set: `stable`, `beta`, `hotfix`, `dev`

## Current state

- module installation and admin integration are live;
- marketplace catalog and license activation are wired to `updates.c0l.ru`;
- package validation and install flow are enforced in code;
- rollback state is stored locally for the last successful install.
