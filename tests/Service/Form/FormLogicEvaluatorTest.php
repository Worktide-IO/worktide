<?php

declare(strict_types=1);

namespace App\Tests\Service\Form;

use App\Service\Form\FormLogicEvaluator;
use App\Service\Form\FormSchemaNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the branching / jump / calc engine. Pure, no container.
 *
 * These fixtures double as the contract the TypeScript port
 * ({@see worktide-portal/src/lib/formLogic.ts}) must reproduce identically.
 */
final class FormLogicEvaluatorTest extends TestCase
{
    private function evaluate(array $rawSchema, array $answers): array
    {
        $form = (new \App\Entity\PublicForm())->setFields([])->setSchema($rawSchema)->setSchemaVersion(2);
        $doc = (new FormSchemaNormalizer())->normalize($form);

        return (new FormLogicEvaluator())->evaluate($doc, $answers);
    }

    public function testShowRuleHidesTargetUntilConditionMatches(): void
    {
        $schema = [
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'ba', 'key' => 'has_site', 'type' => 'select'],
                    ['id' => 'bx', 'key' => 'site_url', 'type' => 'url'],
                ],
            ]],
            'logic' => [
                ['if' => ['all' => [['field' => 'has_site', 'op' => 'eq', 'value' => 'yes']]],
                    'then' => ['action' => 'show', 'target' => 'bx']],
            ],
        ];

        self::assertNotContains('site_url', $this->evaluate($schema, ['has_site' => 'no'])['activeKeys']);
        self::assertContains('site_url', $this->evaluate($schema, ['has_site' => 'yes'])['activeKeys']);
    }

    public function testHideRuleShownByDefault(): void
    {
        $schema = [
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'ba', 'key' => 'kind', 'type' => 'select'],
                    ['id' => 'bx', 'key' => 'detail', 'type' => 'text'],
                ],
            ]],
            'logic' => [
                ['if' => ['all' => [['field' => 'kind', 'op' => 'eq', 'value' => 'simple']]],
                    'then' => ['action' => 'hide', 'target' => 'bx']],
            ],
        ];

        self::assertContains('detail', $this->evaluate($schema, [])['activeKeys']);
        self::assertNotContains('detail', $this->evaluate($schema, ['kind' => 'simple'])['activeKeys']);
    }

    public function testJumpSkipsInterveningPage(): void
    {
        $schema = [
            'pages' => [
                ['id' => 'p1', 'blocks' => [['id' => 'b1', 'key' => 'type', 'type' => 'select']]],
                ['id' => 'p2', 'blocks' => [['id' => 'b2', 'key' => 'mid', 'type' => 'text']]],
                ['id' => 'p3', 'blocks' => [['id' => 'b3', 'key' => 'end', 'type' => 'text']]],
            ],
            'logic' => [
                ['if' => ['all' => [['field' => 'type', 'op' => 'eq', 'value' => 'fast']]],
                    'then' => ['action' => 'jump', 'from' => 'p1', 'target' => 'p3']],
            ],
        ];

        self::assertSame(['p1', 'p2', 'p3'], $this->evaluate($schema, ['type' => 'slow'])['pageOrder']);
        self::assertSame(['p1', 'p3'], $this->evaluate($schema, ['type' => 'fast'])['pageOrder']);
        self::assertNotContains('mid', $this->evaluate($schema, ['type' => 'fast'])['activeKeys']);
    }

    public function testCalcAstEvaluatesAndChains(): void
    {
        $schema = [
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'b1', 'key' => 'a', 'type' => 'number'],
                    ['id' => 'b2', 'key' => 'b', 'type' => 'number'],
                ],
            ]],
            'calc' => [
                ['key' => 'sum', 'ast' => ['op' => '+', 'args' => [['field' => 'a'], ['field' => 'b']]]],
                ['key' => 'doubled', 'ast' => ['op' => '*', 'args' => [['field' => 'sum'], ['const' => 2]]]],
            ],
        ];

        $calc = $this->evaluate($schema, ['a' => 3, 'b' => 4])['calc'];
        self::assertSame(7, $calc['sum']);
        self::assertSame(14, $calc['doubled']);
    }

    public function testDivisionByZeroYieldsZero(): void
    {
        $schema = [
            'pages' => [['id' => 'p1', 'blocks' => [['id' => 'b1', 'key' => 'x', 'type' => 'number']]]],
            'calc' => [['key' => 'r', 'ast' => ['op' => '/', 'args' => [['field' => 'x'], ['const' => 0]]]]],
        ];

        self::assertSame(0, $this->evaluate($schema, ['x' => 10])['calc']['r']);
    }

    public function testCalcValueIsUsableInConditions(): void
    {
        $schema = [
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'b1', 'key' => 'qty', 'type' => 'number'],
                    ['id' => 'bx', 'key' => 'bulk_note', 'type' => 'text'],
                ],
            ]],
            'calc' => [['key' => 'total', 'ast' => ['op' => '*', 'args' => [['field' => 'qty'], ['const' => 100]]]]],
            'logic' => [
                ['if' => ['all' => [['field' => 'total', 'op' => 'gte', 'value' => 1000]]],
                    'then' => ['action' => 'show', 'target' => 'bx']],
            ],
        ];

        self::assertNotContains('bulk_note', $this->evaluate($schema, ['qty' => 5])['activeKeys']);
        self::assertContains('bulk_note', $this->evaluate($schema, ['qty' => 10])['activeKeys']);
    }

    public function testLegacyFlatFormHasAllFieldsActive(): void
    {
        $form = (new \App\Entity\PublicForm())->setFields([
            ['key' => 'name', 'type' => 'text', 'section' => 'A'],
            ['key' => 'email', 'type' => 'email', 'section' => 'B'],
        ]);
        $doc = (new FormSchemaNormalizer())->normalize($form);
        $result = (new FormLogicEvaluator())->evaluate($doc, []);

        self::assertSame(['name', 'email'], $result['activeKeys']);
        self::assertCount(2, $doc['pages'], 'one page per distinct section');
    }
}
