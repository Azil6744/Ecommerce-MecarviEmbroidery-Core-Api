<?php

namespace App\Support;

/**
 * Which notes/todos workspace the current request works in.
 *
 * null  => the shared admin workspace (default, rows with owner_id NULL).
 * int   => that user's personal workspace; set only by the PersonalWorkspace middleware.
 */
class WorkspaceOwner
{
    private static ?int $ownerId = null;

    public static function set(?int $ownerId): void
    {
        self::$ownerId = $ownerId;
    }

    public static function id(): ?int
    {
        return self::$ownerId;
    }

    public static function isPersonal(): bool
    {
        return self::$ownerId !== null;
    }
}
