<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * Pure evaluation of a normalised v2 form document against a set of answers:
 * conditional visibility, page jumps, and computed (calc) fields.
 *
 * Deliberately free of Doctrine / HTTP so it is trivially unit-testable and can
 * be mirrored 1:1 in TypeScript ({@see worktide-portal/src/lib/formLogic.ts}).
 * The server is always authoritative — the portal runs the same rules only for
 * live UX; {@see \App\Service\PublicFormSubmissionService} re-evaluates here on
 * submit so a client can never smuggle a value into a field the logic hid.
 *
 * ## Rule semantics (kept deliberately small)
 *
 * Conditions read the *merged* view (answers ∪ calc results):
 *   op ∈ eq, neq, contains, gt, gte, lt, lte, in, empty, not_empty
 *   grouped by `all` (AND) or `any` (OR).
 *
 * Visibility (blocks and pages), given the rules that `target` them:
 *   - a target with ≥1 `show` rule defaults HIDDEN, becomes visible iff any
 *     show-condition matches;
 *   - a target with only `hide` rules defaults VISIBLE, becomes hidden iff any
 *     hide-condition matches;
 *   - a target with no rules is visible.
 *   A block with `"hidden": true` is a prefill field: never user-visible and
 *   never user-required, regardless of rules.
 *
 * Jumps: walking from the first page, the first matching `jump` rule anchored on
 * the current page sends flow to its target page, skipping everything between.
 * Only pages actually reached are "active".
 *
 * calc: structured AST (+ - * /) over field refs / constants — no string eval,
 * so it is injection-safe and identical across PHP and TS. Division by zero and
 * non-numeric refs yield 0.
 */
final class FormLogicEvaluator
{
    /**
     * @param array{pages: list<array<string, mixed>>, logic: list<array<string, mixed>>, calc: list<array<string, mixed>>} $doc
     * @param array<string, mixed> $answers
     *
     * @return array{
     *   calc: array<string, int|float>,
     *   pageOrder: list<string>,
     *   visiblePages: array<string, bool>,
     *   visibleBlocks: array<string, bool>,
     *   activeKeys: list<string>
     * }
     */
    public function evaluate(array $doc, array $answers): array
    {
        $calc = $this->computeCalc($doc['calc'] ?? [], $answers);
        $merged = $answers;
        foreach ($calc as $k => $v) {
            $merged[$k] = $v;
        }

        $logic = $doc['logic'] ?? [];
        $visiblePages = [];
        $visibleBlocks = [];

        foreach ($doc['pages'] as $page) {
            $pageId = (string) $page['id'];
            $visiblePages[$pageId] = $this->isVisible($pageId, $logic, $merged);
            foreach ($page['blocks'] as $block) {
                $blockId = (string) $block['id'];
                $visibleBlocks[$blockId] = ((bool) ($block['hidden'] ?? false)) === false
                    && $this->isVisible($blockId, $logic, $merged);
            }
        }

        $pageOrder = $this->walkPages($doc, $merged, $visiblePages);

        $activeKeys = [];
        $inOrder = array_fill_keys($pageOrder, true);
        foreach ($doc['pages'] as $page) {
            $pageId = (string) $page['id'];
            if (!isset($inOrder[$pageId]) || ($visiblePages[$pageId] ?? false) === false) {
                continue;
            }
            foreach ($page['blocks'] as $block) {
                $key = (string) ($block['key'] ?? '');
                if ($key === '' || !\in_array($block['type'] ?? '', FormSchemaNormalizer::INPUT_TYPES, true)) {
                    continue;
                }
                if (($block['hidden'] ?? false) === true) {
                    continue; // prefill field — active but set server-side, not user-validated
                }
                if (($visibleBlocks[$block['id']] ?? true) === true) {
                    $activeKeys[] = $key;
                }
            }
        }

        return [
            'calc' => $calc,
            'pageOrder' => $pageOrder,
            'visiblePages' => $visiblePages,
            'visibleBlocks' => $visibleBlocks,
            'activeKeys' => array_values(array_unique($activeKeys)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $logic
     * @param array<string, mixed> $merged
     */
    private function isVisible(string $targetId, array $logic, array $merged): bool
    {
        $hasShow = false;
        $showMatched = false;
        $hideMatched = false;

        foreach ($logic as $rule) {
            $then = \is_array($rule['then'] ?? null) ? $rule['then'] : [];
            if ((string) ($then['target'] ?? '') !== $targetId) {
                continue;
            }
            $action = (string) ($then['action'] ?? '');
            $matches = $this->matchesCondition($rule['if'] ?? [], $merged);
            if ($action === 'show') {
                $hasShow = true;
                $showMatched = $showMatched || $matches;
            } elseif ($action === 'hide') {
                $hideMatched = $hideMatched || $matches;
            }
        }

        if ($hasShow) {
            return $showMatched;
        }

        return !$hideMatched;
    }

    /**
     * Ordered list of reachable page ids, honouring the first matching jump per
     * page. Guards against loops by never revisiting a page.
     *
     * @param array{pages: list<array<string, mixed>>, logic: list<array<string, mixed>>} $doc
     * @param array<string, mixed> $merged
     * @param array<string, bool> $visiblePages
     *
     * @return list<string>
     */
    private function walkPages(array $doc, array $merged, array $visiblePages): array
    {
        $ids = array_map(static fn (array $p): string => (string) $p['id'], $doc['pages']);
        if ($ids === []) {
            return [];
        }
        $indexOf = array_flip($ids);
        $logic = $doc['logic'] ?? [];

        $order = [];
        $seen = [];
        $i = 0;
        while ($i < \count($ids)) {
            $pageId = $ids[$i];
            if (isset($seen[$pageId])) {
                break; // cycle guard
            }
            $seen[$pageId] = true;

            if (($visiblePages[$pageId] ?? true) === true) {
                $order[] = $pageId;
            }

            $jumpTarget = $this->jumpTargetFor($pageId, $logic, $merged);
            if ($jumpTarget !== null && isset($indexOf[$jumpTarget]) && $indexOf[$jumpTarget] > $i) {
                $i = $indexOf[$jumpTarget];
                continue;
            }
            ++$i;
        }

        return $order;
    }

    /**
     * @param list<array<string, mixed>> $logic
     * @param array<string, mixed> $merged
     */
    private function jumpTargetFor(string $pageId, array $logic, array $merged): ?string
    {
        foreach ($logic as $rule) {
            $then = \is_array($rule['then'] ?? null) ? $rule['then'] : [];
            if ((string) ($then['action'] ?? '') !== 'jump') {
                continue;
            }
            // A jump is anchored on a page when its `from` is that page id, or
            // (convenience) when its sole condition field lives on that page. We
            // require an explicit `from` to stay unambiguous.
            if ((string) ($then['from'] ?? '') !== $pageId) {
                continue;
            }
            if ($this->matchesCondition($rule['if'] ?? [], $merged)) {
                return (string) ($then['target'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param mixed $condition
     * @param array<string, mixed> $merged
     */
    private function matchesCondition(mixed $condition, array $merged): bool
    {
        if (!\is_array($condition)) {
            return true;
        }
        if (isset($condition['all']) && \is_array($condition['all'])) {
            foreach ($condition['all'] as $atom) {
                if (!$this->matchesAtom($atom, $merged)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($condition['any']) && \is_array($condition['any'])) {
            foreach ($condition['any'] as $atom) {
                if ($this->matchesAtom($atom, $merged)) {
                    return true;
                }
            }

            return false;
        }
        // A bare atom is also accepted.
        if (isset($condition['field'])) {
            return $this->matchesAtom($condition, $merged);
        }

        return true;
    }

    /**
     * @param mixed $atom
     * @param array<string, mixed> $merged
     */
    private function matchesAtom(mixed $atom, array $merged): bool
    {
        if (!\is_array($atom) || !isset($atom['field'])) {
            return false;
        }
        $field = (string) $atom['field'];
        $op = (string) ($atom['op'] ?? 'eq');
        $expected = $atom['value'] ?? null;
        $actual = $merged[$field] ?? null;

        return match ($op) {
            'eq' => $this->looseEquals($actual, $expected),
            'neq' => !$this->looseEquals($actual, $expected),
            'empty' => $this->isEmpty($actual),
            'not_empty' => !$this->isEmpty($actual),
            'contains' => $this->contains($actual, $expected),
            'in' => \is_array($expected) && $this->inList($actual, $expected),
            'gt' => $this->toNumber($actual) > $this->toNumber($expected),
            'gte' => $this->toNumber($actual) >= $this->toNumber($expected),
            'lt' => $this->toNumber($actual) < $this->toNumber($expected),
            'lte' => $this->toNumber($actual) <= $this->toNumber($expected),
            default => false,
        };
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        if (\is_bool($a) || \is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return $this->toNumber($a) === $this->toNumber($b);
        }

        return (string) $a === (string) $b;
    }

    private function isEmpty(mixed $v): bool
    {
        if ($v === null || $v === '' || $v === false) {
            return true;
        }

        return \is_array($v) && $v === [];
    }

    private function contains(mixed $haystack, mixed $needle): bool
    {
        if (\is_array($haystack)) {
            return $this->inList($needle, $haystack);
        }

        return $needle !== null && str_contains((string) $haystack, (string) $needle);
    }

    /**
     * @param list<mixed> $list
     */
    private function inList(mixed $value, array $list): bool
    {
        foreach ($list as $item) {
            if ($this->looseEquals($value, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $calc
     * @param array<string, mixed> $answers
     *
     * @return array<string, int|float>
     */
    private function computeCalc(array $calc, array $answers): array
    {
        $out = [];
        $scope = $answers;
        foreach ($calc as $rule) {
            $key = (string) ($rule['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $value = $this->evalAst($rule['ast'] ?? null, $scope);
            $out[$key] = $value;
            $scope[$key] = $value; // later calc rules may reference earlier ones
        }

        return $out;
    }

    /**
     * Hard cap on calc-AST nesting. A calc AST is authored into the (staff-owned)
     * form schema but evaluated on every ANONYMOUS submit, so a pathologically
     * deep tree would otherwise blow the PHP stack on a public endpoint. 64 is
     * far beyond any legitimate formula; deeper branches evaluate to 0.
     */
    private const MAX_AST_DEPTH = 64;

    /**
     * @param mixed $node
     * @param array<string, mixed> $scope
     */
    private function evalAst(mixed $node, array $scope, int $depth = 0): int|float
    {
        if ($depth > self::MAX_AST_DEPTH || !\is_array($node)) {
            return 0;
        }
        if (\array_key_exists('const', $node)) {
            return is_numeric($node['const']) ? $this->toNumber($node['const']) : 0;
        }
        if (\array_key_exists('field', $node)) {
            return $this->toNumber($scope[(string) $node['field']] ?? 0);
        }
        $op = (string) ($node['op'] ?? '');
        $args = \is_array($node['args'] ?? null) ? $node['args'] : [];
        $values = array_map(fn ($a): int|float => $this->evalAst($a, $scope, $depth + 1), $args);
        if ($values === []) {
            return 0;
        }

        $acc = array_shift($values);
        foreach ($values as $v) {
            $acc = match ($op) {
                '+' => $acc + $v,
                '-' => $acc - $v,
                '*' => $acc * $v,
                '/' => $v == 0.0 ? 0 : $acc / $v,
                default => $acc,
            };
        }

        return $acc;
    }

    private function toNumber(mixed $v): int|float
    {
        if (\is_int($v) || \is_float($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return $v + 0;
        }

        return 0;
    }
}
