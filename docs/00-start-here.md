# Randee Update - start here

This repository contains the Bitrix client module `randee.update`.

If you are new to the project, read this in order:

1. [README.md](../README.md) - short overview.
2. [docs/01-beginner-guide.md](01-beginner-guide.md) - what the module does and how to use it.
3. [docs/02-package-format.md](02-package-format.md) - how a release ZIP must be built.
4. [docs/03-installation-and-troubleshooting.md](03-installation-and-troubleshooting.md) - common errors and recovery steps.

## One-sentence summary

`randee.update` is the Bitrix-side client that connects to `updates.c0l.ru`, downloads a release package,
checks its manifest, installs only the payload, and stores rollback state locally.

## What lives where

- Marketplace metadata and release records live on the server.
- The Bitrix module lives in this repository and on the client site.
- Release archives are built separately and uploaded to the marketplace.
- Installed files end up in the Bitrix filesystem of the client site.

## If you only need the shortest path

1. Install the module.
2. Open the marketplace page.
3. Save settings.
4. Activate the license.
5. Open a product card.
6. Download the package.
7. Install it.

If a step fails, open [docs/03-installation-and-troubleshooting.md](03-installation-and-troubleshooting.md).
