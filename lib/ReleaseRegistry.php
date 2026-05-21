<?php

namespace Randee\Update;

final class ReleaseRegistry
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

        return [
            'randee.update' => [
                'id' => 'randee.update',
                'kind' => 'module',
                'title' => 'Randee Update',
                'description' => 'Центр управления релизами',
                'version_file' => $root . '/local/modules/randee.update/install/version.php',
                'default_manifest_source' => '/local/update/manifest.json',
                'supports_actions' => true,
            ],
            'randee.menu' => [
                'id' => 'randee.menu',
                'kind' => 'module',
                'title' => 'Randee Menu',
                'description' => 'Управление меню сайта',
                'version_file' => $root . '/local/modules/randee.menu/install/version.php',
                'default_manifest_source' => '/local/update/randee.menu/manifest.json',
                'supports_actions' => true,
            ],
            'legacy.template' => [
                'id' => 'legacy.template',
                'kind' => 'template',
                'title' => 'Legacy template',
                'description' => 'Шаблон сайта',
                'version_file' => $root . '/local/templates/legacy/version.php',
                'default_manifest_source' => '/local/update/templates/legacy/manifest.json',
                'supports_actions' => true,
            ],
            'randee.hero' => [
                'id' => 'randee.hero',
                'kind' => 'component',
                'title' => 'Randee Hero',
                'description' => 'Геро-секция и связанные настройки',
                'version_file' => $root . '/local/components/randee/hero/version.php',
                'default_manifest_source' => '/local/update/components/randee.hero/manifest.json',
                'supports_actions' => true,
            ],
        ];
    }

    public function find(string $entityId): ?array
    {
        $entities = $this->all();

        return $entities[$entityId] ?? null;
    }
}
