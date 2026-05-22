# Как добавить `Randee: главный hero` в marketplace

Этот файл нужен новичку, который хочет собрать свой первый пакет и добавить его в `updates.c0l.ru`.

## 1. Что именно создаём

Для hero-блока используем `component`, а не `module`.

Рекомендуемые значения:

- `name`: `Randee: главный hero`
- `product_id`: `randee.hero`
- `type`: `component`

## 2. Что должно быть внутри пакета

Пакет всегда собирается как ZIP.

Внутри ZIP должны быть только:

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

Если вы кладёте туда другие файлы, которые не нужны компоненту, лучше их убрать.

## 3. Файл `package.json`

Это главный файл пакета. Без него пакет не принимается.

Пример:

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

## 4. Минимальные файлы компонента

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

## 5. Какие данные должны быть в инфоблоках

Основной источник данных:

- infoblock ID `1`, symbolic code `randeehero`;
- infoblock ID `2`, symbolic code `randee_hero_slides`.

Hero-компонент читает из `randeehero`:

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

Связь `SCREEN_SLIDES` должна указывать на элементы инфоблока `randee_hero_slides`.
Картинки для слайдов берутся из `PREVIEW_PICTURE` этих элементов.
Если `PREVIEW_PICTURE` пустой, компонент может взять `DETAIL_PICTURE` как запасной вариант.

## 6. Как собрать ZIP

Собирайте архив так, чтобы `package.json` лежал в корне ZIP, а код - внутри `payload/`.

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

## 7. Как добавить в marketplace

1. Откройте `updates.c0l.ru/admin`.
2. Перейдите в `Products`.
3. Создайте продукт:
   - `product_id`: `randee.hero`
   - `type`: `component`
   - `name`: `Randee: главный hero`
4. Перейдите в `Packages`.
5. Загрузите ZIP-пакет.
6. Перейдите в `Releases`.
7. Создайте релиз:
   - `version`
   - `release_tag`
   - `build_number`
   - `channel`
   - привяжите пакет
8. Нажмите `Publish`.

После этого компонент станет доступен в каталоге и сможет устанавливаться через `randee.update`.

## 8. Куда он попадёт на сайте клиента

После установки файлы окажутся в:

```text
DOCUMENT_ROOT/local/components/randee/hero/
```

## 9. Короткое правило

Если совсем просто:

1. Сначала создайте продукт.
2. Потом загрузите ZIP.
3. Потом создайте релиз.
4. Потом опубликуйте.
5. И только после этого пакет можно ставить на сайт клиента.
