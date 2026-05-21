<?php

namespace Randee\Update;

final class VersionComparator
{
    public static function compare(string $currentVersion, string $availableVersion): int
    {
        $currentVersion = trim($currentVersion);
        $availableVersion = trim($availableVersion);

        if ($currentVersion === '' || $availableVersion === '') {
            return 0;
        }

        return version_compare($availableVersion, $currentVersion);
    }

    public static function isUpdateAvailable(string $currentVersion, string $availableVersion): bool
    {
        return self::compare($currentVersion, $availableVersion) > 0;
    }
}
