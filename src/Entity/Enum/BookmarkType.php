<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The protocol / scheme a {@see \App\Entity\CustomerBookmark} represents.
 * Mirrors the standard URI schemes plus common database connection protocols.
 */
enum BookmarkType: string
{
    case Web = 'web';
    case Ssh = 'ssh';
    case Sftp = 'sftp';
    case Ftp = 'ftp';
    case Rdp = 'rdp';
    case Vnc = 'vnc';
    case Database = 'database';

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Web => null,
            self::Ssh, self::Sftp => 22,
            self::Ftp => 21,
            self::Rdp => 3389,
            self::Vnc => 5900,
            self::Database => null, // depends on driver (3306, 5432, …)
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web / Admin-URL',
            self::Ssh => 'SSH',
            self::Sftp => 'SFTP',
            self::Ftp => 'FTP / FTPS',
            self::Rdp => 'Remote Desktop (RDP)',
            self::Vnc => 'VNC',
            self::Database => 'Datenbank',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Web => 'Globe',
            self::Ssh => 'Terminal',
            self::Sftp => 'FolderSync',
            self::Ftp => 'FolderUp',
            self::Rdp => 'Monitor',
            self::Vnc => 'Monitor',
            self::Database => 'Database',
        };
    }
}
