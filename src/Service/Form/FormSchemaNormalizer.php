<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\PublicForm;

/**
 * Single source of truth for a {@see PublicForm}'s *structure*.
 *
 * Everything downstream — the portal/public schema DTOs, the submission
 * validator, the branching/calc engine — consumes the normalised v2 document
 * this service produces, never the raw {@see PublicForm::getSchema()} column or
 * the legacy {@see PublicForm::getFields()} array. That keeps exactly one code
 * path: a v1 (flat) form is transparently lifted into an equivalent v2 document
 * (one page per distinct `section`, in first-seen order), so no data migration
 * is needed and every existing form keeps working.
 *
 * ## Normalised v2 document shape
 *
 *   {
 *     "version": 2,
 *     "pages": [
 *       { "id": "p1", "title": string|null, "blocks": [ <block>, ... ] }
 *     ],
 *     "logic": [
 *       { "if": <condition>, "then": { "action": "show"|"hide"|"jump", "target": string } }
 *     ],
 *     "calc": [
 *       { "key": string, "ast": <ast> }
 *     ]
 *   }
 *
 * block:
 *   { "id": string,            // stable id, referenced by logic `target`
 *     "key": string,           // answer key (input blocks); "" for static blocks
 *     "type": string,          // text|long_text|number|boolean|select|email|url|date
 *                              //  |multi_select|rating|scale|matrix|file|heading|paragraph
 *     "label": string,
 *     "required": bool,
 *     "options": list<string>,
 *     "placeholder": string|null,
 *     "hidden": bool,          // never rendered; value comes from prefill
 *     "prefillFrom": string|null, // whitelisted source (see FormPrefillResolver)
 *     "min": int|null,         // rating/scale bounds
 *     "max": int|null,
 *     "rows": list<string>,    // matrix row labels
 *     "mapsTo": string|null }  // INTERNAL — never exposed by a DTO
 *
 * condition:
 *   { "all": [ <atom>, ... ] } | { "any": [ <atom>, ... ] }
 *   atom: { "field": string, "op": string, "value"?: mixed }
 *
 * ast (calc):
 *   { "op": "+"|"-"|"*"|"/", "args": [ <node>, ... ] }
 *   node: { "field": string } | { "const": int|float } | <ast>
 */
final class FormSchemaNormalizer
{
    /** Block types that collect an answer (everything else is presentational). */
    public const INPUT_TYPES = [
        'text', 'long_text', 'number', 'boolean', 'select', 'email', 'url', 'date',
        'multi_select', 'rating', 'scale', 'matrix', 'file',
    ];

    /**
     * @return array{version: int, pages: list<array<string, mixed>>, logic: list<array<string, mixed>>, calc: list<array<string, mixed>>}
     */
    public function normalize(PublicForm $form): array
    {
        $schema = $form->getSchema();
        if ($form->getSchemaVersion() >= 2 && \is_array($schema) && isset($schema['pages'])) {
            return $this->normalizeV2($schema);
        }

        return $this->fromLegacyFields($form->getFields());
    }

    /**
     * Flatten a normalised document to the list of input blocks (in document
     * order), the way the legacy submission loop iterated {@see PublicForm::getFields()}.
     *
     * @param array{pages: list<array<string, mixed>>, ...} $doc
     *
     * @return list<array<string, mixed>>
     */
    public function inputBlocks(array $doc): array
    {
        $blocks = [];
        foreach ($doc['pages'] as $page) {
            foreach ($page['blocks'] as $block) {
                if (\in_array($block['type'] ?? '', self::INPUT_TYPES, true)) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * Client-safe projection of a normalised document for a renderer: strips the
     * internal `mapsTo` and `prefillFrom` from every block (a renderer needs
     * neither, and both are server-only routing details), keeps structure, logic
     * and calc. Hidden blocks stay in so the client knows to skip them.
     *
     * @param array{version: int, pages: list<array<string, mixed>>, logic: list<array<string, mixed>>, calc: list<array<string, mixed>>} $doc
     *
     * @return array<string, mixed>
     */
    public function toClientSchema(array $doc): array
    {
        $pages = array_map(static function (array $page): array {
            $page['blocks'] = array_map(static function (array $block): array {
                unset($block['mapsTo'], $block['prefillFrom']);

                return $block;
            }, $page['blocks']);

            return $page;
        }, $doc['pages']);

        // Calc keys/AST are safe to expose so the renderer can show live totals,
        // but a calc rule may also carry internal routing (`mapsTo`/`prefillFrom`)
        // — strip those, like we do for input blocks, so the anonymous
        // GET /v1/forms/{slug} never leaks internal custom-field/task keys.
        $calc = array_map(static function (array $rule): array {
            unset($rule['mapsTo'], $rule['prefillFrom']);

            return $rule;
        }, $doc['calc']);

        return [
            'version' => $doc['version'],
            'pages' => $pages,
            'logic' => $doc['logic'],
            'calc' => $calc,
        ];
    }

    /**
     * Flat, client-safe field list (back-compat with the pre-engine DTO): the
     * input blocks in document order, `mapsTo`/`prefillFrom` stripped, with the
     * owning page title surfaced as `section` so the old section-wizard renderer
     * keeps working.
     *
     * @param array{pages: list<array<string, mixed>>, ...} $doc
     *
     * @return list<array<string, mixed>>
     */
    public function toClientFields(array $doc): array
    {
        $fields = [];
        foreach ($doc['pages'] as $page) {
            $section = $page['title'] ?? null;
            foreach ($page['blocks'] as $block) {
                if (!\in_array($block['type'] ?? '', self::INPUT_TYPES, true)) {
                    continue;
                }
                $fields[] = [
                    'key' => $block['key'],
                    'label' => $block['label'],
                    'labelI18n' => $block['labelI18n'] ?? [],
                    'type' => $block['type'],
                    'required' => $block['required'],
                    'options' => $block['options'],
                    'placeholder' => $block['placeholder'],
                    'section' => $section,
                ];
            }
        }

        return $fields;
    }

    /**
     * Coerce a stored v2 document into the canonical shape, filling defaults so
     * consumers never have to null-check individual block attributes.
     *
     * @param array<string, mixed> $schema
     *
     * @return array{version: int, pages: list<array<string, mixed>>, logic: list<array<string, mixed>>, calc: list<array<string, mixed>>}
     */
    private function normalizeV2(array $schema): array
    {
        $pages = [];
        $pageIndex = 0;
        foreach ((array) ($schema['pages'] ?? []) as $rawPage) {
            $rawPage = \is_array($rawPage) ? $rawPage : [];
            ++$pageIndex;
            $pageId = (string) ($rawPage['id'] ?? 'p' . $pageIndex);
            $blocks = [];
            $blockIndex = 0;
            foreach ((array) ($rawPage['blocks'] ?? []) as $rawBlock) {
                $rawBlock = \is_array($rawBlock) ? $rawBlock : [];
                ++$blockIndex;
                $blocks[] = $this->normalizeBlock($rawBlock, $pageId, $blockIndex);
            }
            $pages[] = [
                'id' => $pageId,
                'title' => isset($rawPage['title']) ? (string) $rawPage['title'] : null,
                'blocks' => $blocks,
            ];
        }

        return [
            'version' => 2,
            'pages' => $pages,
            'logic' => array_values(array_filter(
                (array) ($schema['logic'] ?? []),
                static fn ($r): bool => \is_array($r),
            )),
            'calc' => array_values(array_filter(
                (array) ($schema['calc'] ?? []),
                static fn ($r): bool => \is_array($r) && isset($r['key']),
            )),
        ];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalizeBlock(array $raw, string $pageId, int $index): array
    {
        $key = (string) ($raw['key'] ?? '');

        // Per-locale label overrides ({locale: string}); the renderer overlays the
        // active locale client-side (content i18n stays client-side, never a
        // server overlay). Kept in the client schema so the portal can localize.
        $labelI18n = [];
        foreach ((array) ($raw['labelI18n'] ?? []) as $loc => $val) {
            if (\is_string($val) && $val !== '') {
                $labelI18n[(string) $loc] = $val;
            }
        }

        return [
            'id' => (string) ($raw['id'] ?? $pageId . '-b' . $index),
            'key' => $key,
            'type' => (string) ($raw['type'] ?? 'text'),
            'label' => (string) ($raw['label'] ?? ($key !== '' ? $key : '')),
            'labelI18n' => $labelI18n,
            'required' => (bool) ($raw['required'] ?? false),
            'options' => array_values(array_map('strval', (array) ($raw['options'] ?? []))),
            'placeholder' => isset($raw['placeholder']) ? (string) $raw['placeholder'] : null,
            'hidden' => (bool) ($raw['hidden'] ?? false),
            'prefillFrom' => isset($raw['prefillFrom']) && $raw['prefillFrom'] !== '' ? (string) $raw['prefillFrom'] : null,
            'min' => isset($raw['min']) ? (int) $raw['min'] : null,
            'max' => isset($raw['max']) ? (int) $raw['max'] : null,
            'rows' => array_values(array_map('strval', (array) ($raw['rows'] ?? []))),
            'mapsTo' => isset($raw['mapsTo']) && $raw['mapsTo'] !== '' ? (string) $raw['mapsTo'] : null,
        ];
    }

    /**
     * Lift a legacy flat field list into an equivalent v2 document: one page per
     * distinct `section` (first-seen order); no logic, no calc.
     *
     * @param list<array<string, mixed>> $fields
     *
     * @return array{version: int, pages: list<array<string, mixed>>, logic: list<array<string, mixed>>, calc: list<array<string, mixed>>}
     */
    private function fromLegacyFields(array $fields): array
    {
        /** @var array<string, array<string, mixed>> $bySection section label => page */
        $bySection = [];
        $order = [];
        $index = 0;

        foreach ($fields as $field) {
            if (!\is_array($field)) {
                continue;
            }
            ++$index;
            $section = isset($field['section']) && $field['section'] !== '' ? (string) $field['section'] : '';
            if (!isset($bySection[$section])) {
                $bySection[$section] = [
                    'id' => 'p' . (\count($order) + 1),
                    'title' => $section !== '' ? $section : null,
                    'blocks' => [],
                ];
                $order[] = $section;
            }
            $pageId = $bySection[$section]['id'];
            $bySection[$section]['blocks'][] = $this->normalizeBlock($field, $pageId, $index);
        }

        if ($order === []) {
            // A form with no fields still needs one (empty) page so consumers can
            // assume pages[0] exists.
            $bySection[''] = ['id' => 'p1', 'title' => null, 'blocks' => []];
            $order[] = '';
        }

        return [
            'version' => 2,
            'pages' => array_map(static fn (string $s): array => $bySection[$s], $order),
            'logic' => [],
            'calc' => [],
        ];
    }
}
