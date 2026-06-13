<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How the document body is encoded — clients pick the format they can render.
 * MVP defaults to markdown; richtext lets future editors (TipTap / ProseMirror /
 * Lexical) store a structured JSON tree alongside the rendered markdown view.
 */
enum DocumentBodyFormat: string
{
    case Markdown = 'markdown';
    case Html = 'html';
    case Richtext = 'richtext';
}
