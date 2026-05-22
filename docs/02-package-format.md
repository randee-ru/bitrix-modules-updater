# Формат пакета Randee

Этот документ объясняет, как должен быть собран ZIP-релиз.

## Обязательная структура архива

В ZIP должны быть:

```text
package.json
payload/
  ...
```

Файл `package.json` должен лежать в корне архива.

## Обязательные поля `package.json`

Манифест должен содержать:

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

## Пример манифеста

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

## Допустимые значения канала

- `stable`
- `beta`
- `hotfix`
- `dev`

## Что проверяет установщик

Перед установкой модуль проверяет:

- that the ZIP can be downloaded;
- that `package.json` exists;
- that the manifest is valid JSON;
- that the archive uses `payload/` as installation root;
- that the declared paths are safe;
- that the package matches the product the admin selected.

## Что происходит, если манифест неверный

Если пакет неверный, установка останавливается, а интерфейс показывает причину.
Частые ошибки:

- `package.json` is missing;
- `format` or `type` is wrong;
- the archive structure is incorrect;
- the package does not match the selected product.
