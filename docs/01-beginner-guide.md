# Randee Update - beginner guide

This guide explains the module in plain language.

## 1. What `randee.update` is

`randee.update` is the Bitrix client module that installs and updates Randee products.

It does not store the marketplace catalog itself. Instead, it asks the marketplace server
`updates.c0l.ru` what products are available, which versions exist, and whether your license allows them.

## 2. What problems it solves

- You do not need to upload files by hand for every update.
- The module can install modules, components, and templates from signed packages.
- The server controls which products are visible for your license.
- The module keeps rollback data so the last update can be reverted.

## 3. The main parts

### Marketplace server

This is the central service that stores:

- products;
- releases;
- licenses;
- access rules;
- package metadata.

### Bitrix client module

This repository is the client side. It contains:

- admin page entrypoints;
- installer/uninstaller;
- marketplace API client;
- package validator;
- package downloader;
- package installer;
- rollback helpers;
- logging helpers.

### Release package

This is the ZIP file you upload to the marketplace. It must contain:

- `package.json` in the archive root;
- a `payload/` folder with the files that will be copied to the Bitrix site.

## 4. How a release is installed

1. The admin opens the marketplace page in Bitrix.
2. The module connects to `updates.c0l.ru`.
3. The license is activated.
4. The catalog is loaded.
5. The admin opens a product card.
6. The package is downloaded to a writable temp directory.
7. The package is validated.
8. The installer copies files from `payload/` into the Bitrix filesystem.
9. The installed version is saved.
10. The UI shows the result and the current state.

## 5. What the manifest means

`package.json` tells the module:

- which product the archive belongs to;
- what type of product it is;
- which version it contains;
- which channel it belongs to;
- which folders are allowed to be installed;
- whether the package matches the expected format.

If `package.json` is missing, the module stops installation.

## 6. What to do if you are not sure

Read the files in this order:

- `README.md`
- `docs/00-start-here.md`
- `docs/02-package-format.md`
- `docs/03-installation-and-troubleshooting.md`

If you still need the code flow, inspect:

- `include.php`
- `install/index.php`
- `lib/MarketplaceClient.php`
- `lib/PackageDownloader.php`
- `lib/PackageValidator.php`
- `lib/PackageInstaller.php`
