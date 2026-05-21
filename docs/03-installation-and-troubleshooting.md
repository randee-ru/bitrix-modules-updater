# Installation and troubleshooting

This guide explains how to install `randee.update` and what to check when something fails.

## Installation

1. Copy the module to `local/modules/randee.update/`.
2. Install it from the Bitrix admin panel.
3. Open the marketplace page.
4. Save the marketplace URL, license key, and site UID.
5. Activate the license.
6. Open a product card.
7. Download the package.
8. Install the package.

## Where files are written

- Temporary downloads are stored in a writable temp directory.
- Installed files are copied into the Bitrix filesystem.
- Rollback state is stored locally by the module.

## Common issues

### `package.json` is missing

The archive was built incorrectly. Rebuild the ZIP so that `package.json` is in the archive root.

### `Не удалось создать каталог назначения`

The installer cannot write to the target path. Check filesystem permissions and whether the destination is valid for that product.

### `Сервер вернул неожиданный ответ`

The module received HTML or another non-JSON response instead of the expected API response.
Check the marketplace URL, network access, and authentication.

### `Не удалось подготовить временный каталог`

The configured temp directory is not writable. Set `temp_dir` in the module settings or let the module use fallback directories.

## What to check first

- Does the Bitrix server reach `updates.c0l.ru`?
- Is the license active?
- Is the selected product correct?
- Is the package ZIP valid?
- Does `package.json` exist in the archive root?
- Is the temp directory writable?

## When to use rollback

Use rollback when:

- the last update broke the site;
- the package installed but the result is wrong;
- you need to return to the previous working state.

Rollback only works for the last successful install that the module has stored locally.
