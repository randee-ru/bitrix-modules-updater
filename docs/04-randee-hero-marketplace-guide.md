# Как добавить `Randee: главный hero` в marketplace

This guide is for a beginner who wants to build the first `randee.hero` package and publish it in `updates.c0l.ru`.

## 1. What we are creating

For the hero block we use a `component`, not a `module`.

Recommended values:

- `name`: `Randee: главный hero`
- `product_id`: `randee.hero`
- `type`: `component`

## 2. What must be inside the package

The package is always built as a ZIP archive.

Inside the ZIP there must be only:

```text
package.json
payload/
  local/
    components/
      randee/
        hero/
          .description.php
          component.php
          .parameters.php
          templates/
            .default/
              template.php
```

If you put extra files into the archive, remove them unless they are really needed by the component.

## 3. `package.json`

This is the main package file. Without it the package is not accepted.

Example:

```json
{
  "format": "randee-package",
  "format_version": "1.0",
  "product_id": "randee.hero",
  "type": "component",
  "name": "Randee: главный hero",
  "version": "1.1.1",
  "channel": "stable",
  "release_tag": "v1.1.1",
  "build_number": "2026.05.17.3",
  "install_root": "payload",
  "paths": [
    "local/components/randee/hero"
  ],
  "requires": {
    "php": ">=8.1",
    "bitrix": ">=23.0.0",
    "modules": []
  }
}
```

## 4. Minimal component files

### `.description.php`

```php
<?php
$arComponentDescription = [
    'NAME' => 'Randee: главный hero',
    'DESCRIPTION' => 'Главный hero-блок для страницы',
    'PATH' => [
        'ID' => 'randee',
        'NAME' => 'Randee',
    ],
];
```

### `component.php`

```php
<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$this->IncludeComponentTemplate();
```

### `.parameters.php`

```php
<?php
$arComponentParameters = [
    'PARAMETERS' => [
        'HERO_IBLOCK_ID' => [
            'PARENT' => 'BASE',
            'NAME' => 'Инфоблок hero',
            'TYPE' => 'STRING',
            'DEFAULT' => '1',
        ],
        'HERO_SLIDES_IBLOCK_ID' => [
            'PARENT' => 'BASE',
            'NAME' => 'Инфоблок слайдов',
            'TYPE' => 'STRING',
            'DEFAULT' => '2',
        ],
        'HERO_SLIDES_PROPERTY_CODE' => [
            'PARENT' => 'BASE',
            'NAME' => 'Код свойства со слайдами',
            'TYPE' => 'STRING',
            'DEFAULT' => 'SCREEN_SLIDES',
        ],
    ],
];
```

### `templates/.default/template.php`

```php
<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}
?>
<section class="randee-hero">
    <h1><?= htmlspecialcharsbx($arParams['TITLE'] ?? 'Randee Hero') ?></h1>
</section>
```

## 5. What data must exist in Bitrix infoblocks

The main data source:

- infoblock ID `1`, symbolic code `randeehero`;
- infoblock ID `2`, symbolic code `randee_hero_slides`.

The hero component reads from `randeehero`:

- `TITLE_LINE_1`
- `TITLE_LINE_2`
- `LEAD`
- `BTN_PRIMARY`
- `BTN_PRIMARY_LINK`
- `BTN_OUTLINE`
- `BTN_OUTLINE_LINK`
- `MACBOOK_IMG`
- `ROBOT_IMG`
- `ROBOT_ALT`
- `SCREEN_SLIDES`
- `CONTENT_COL_MD`
- `VISUAL_COL_MD`
- `TITLE_SM`
- `SHOW_MASANYA`

`SCREEN_SLIDES` must point to elements of the `randee_hero_slides` infoblock.
For slide images the component uses `PREVIEW_PICTURE`.
If `PREVIEW_PICTURE` is empty, it may use `DETAIL_PICTURE` as a fallback.

## 6. How to build the ZIP

Build the archive so that `package.json` is in the root of the ZIP and the code is inside `payload/`.

Correct:

```text
package.json
payload/...
```

Wrong:

```text
randee-hero/
  package.json
  payload/...
```

## 7. How to add it to marketplace

1. Open `updates.c0l.ru/admin`.
2. Go to `Products`.
3. Create a product:
   - `product_id`: `randee.hero`
   - `type`: `component`
   - `name`: `Randee: главный hero`
4. Go to `Packages`.
5. Upload the ZIP package.
6. Go to `Releases`.
7. Create a release:
   - `version`
   - `release_tag`
   - `build_number`
   - `channel`
   - attach the package
8. Click `Publish`.

After that the component becomes available in the catalog and can be installed through `randee.update`.

## 8. Where it goes on the client site

After installation the files are placed in:

```text
DOCUMENT_ROOT/local/components/randee/hero/
```

## 9. Short rule

If you want the shortest path:

1. Create the product.
2. Upload the ZIP.
3. Create the release.
4. Publish it.
5. Only then can the package be installed on the client site.
