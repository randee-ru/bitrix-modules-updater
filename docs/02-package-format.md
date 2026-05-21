# Randee package format

This document explains how a release ZIP must be built.

## Required archive layout

The ZIP must contain:

```text
package.json
payload/
  ...
```

The `package.json` file must be in the root of the archive.

## Required `package.json` fields

The manifest must include:

- `format`
- `format_version`
- `product_id`
- `type`
- `version`
- `channel`
- `release_tag`
- `build_number`
- `install_root`
- `paths`

## Example manifest

```json
{
  "format": "randee-package",
  "format_version": "1.0",
  "product_id": "randee.menu",
  "type": "module",
  "version": "1.0.5",
  "channel": "stable",
  "release_tag": "v1.0.5",
  "build_number": "2026.05.22.1",
  "install_root": "payload",
  "paths": [
    "local/modules/randee.menu"
  ]
}
```

## Allowed channel values

- `stable`
- `beta`
- `hotfix`
- `dev`

## What the installer checks

Before installation the module checks:

- that the ZIP can be downloaded;
- that `package.json` exists;
- that the manifest is valid JSON;
- that the archive uses `payload/` as installation root;
- that the declared paths are safe;
- that the package matches the product the admin selected.

## What happens if the manifest is invalid

If the package is invalid, installation stops and the UI shows the reason.
Common failures:

- `package.json` is missing;
- `format` or `type` is wrong;
- the archive structure is incorrect;
- the package does not match the selected product.
