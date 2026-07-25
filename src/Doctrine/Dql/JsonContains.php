<?php

declare(strict_types=1);

namespace App\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * MySQL JSON_CONTAINS(doc, candidate[, path]) as a DQL function.
 *
 * Enables filtering JSON columns from DQL (API Platform collection filters) —
 * used by {@see \App\ApiPlatform\Filter\JsonAttributeFilter} to facet products
 * by arbitrary Product.attributes keys. Returns 1 when the candidate JSON is
 * contained at the (optional) path.
 */
final class JsonContains extends FunctionNode
{
    private Node $doc;
    private Node $candidate;
    private ?Node $path = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->doc = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->candidate = $parser->StringPrimary();

        if ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->path = $parser->StringPrimary();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        if ($this->path !== null) {
            return sprintf(
                'JSON_CONTAINS(%s, %s, %s)',
                $this->doc->dispatch($sqlWalker),
                $this->candidate->dispatch($sqlWalker),
                $this->path->dispatch($sqlWalker),
            );
        }

        return sprintf(
            'JSON_CONTAINS(%s, %s)',
            $this->doc->dispatch($sqlWalker),
            $this->candidate->dispatch($sqlWalker),
        );
    }
}
