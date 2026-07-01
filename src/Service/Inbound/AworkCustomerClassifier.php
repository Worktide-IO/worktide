<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/**
 * Classifies flat awork "company" records into the real company→contact
 * hierarchy that awork itself can't express.
 *
 * awork has no first-class company-with-contacts; users abused the `industry`
 * field to note the actual company on a person record. So the rule is:
 *   - `industry` set          → the record is an Ansprechpartner OF that company
 *   - `industry` empty, name looks like a person → a private person
 *   - `industry` empty, otherwise → the company itself
 *
 * An overrides map (keyed by the awork record's `name`) corrects the fuzzy
 * cases and is the single source shared by both the import and the rebuild
 * command — so a re-import reproduces the same clean hierarchy deterministically.
 *
 * Overrides entry shape (all keys except `type` optional):
 *   "FIR RWTH":          { "type": "company" }
 *   "WapplerSystems":    { "type": "ignore" }
 *   "Jörg Weimann …":    { "type": "contact", "company": "Stadt Griesheim",
 *                          "firstName": "Jörg", "lastName": "Weimann" }
 *   "Some Person":       { "type": "person", "firstName": "…", "lastName": "…" }
 */
final class AworkCustomerClassifier
{
    /** @param array<string, array<string, mixed>> $overrides keyed by awork record name */
    public function __construct(private readonly array $overrides = [])
    {
    }

    public static function fromFile(?string $path): self
    {
        $overrides = [];
        if ($path !== null && is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (\is_array($decoded)) {
                $overrides = $decoded;
            }
        }

        return new self($overrides);
    }

    /** @param array<string, mixed> $row an awork companies.json entry */
    public function classify(array $row): AworkCustomerClassification
    {
        $name = trim((string) ($row['name'] ?? ''));
        $industry = trim((string) ($row['industry'] ?? ''));

        $override = $this->overrides[$name] ?? null;
        if (\is_array($override)) {
            return $this->fromOverride($override, $name, $industry);
        }

        if ($industry !== '') {
            [$first, $last] = $this->splitName($name);

            return new AworkCustomerClassification(AworkCustomerClassification::CONTACT, $industry, $first, $last);
        }
        if ($this->looksLikePerson($name)) {
            [$first, $last] = $this->splitName($name);

            return new AworkCustomerClassification(AworkCustomerClassification::PERSON, null, $first, $last);
        }

        return new AworkCustomerClassification(AworkCustomerClassification::COMPANY, $name);
    }

    /** Deterministic externalId for a company derived from a name (not an awork id). */
    public static function companyRef(string $companyName): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim($companyName))) ?? '';

        return 'aw-co:' . trim($slug, '-');
    }

    /**
     * @param array<string, mixed> $o
     */
    private function fromOverride(array $o, string $name, string $industry): AworkCustomerClassification
    {
        $type = (string) ($o['type'] ?? AworkCustomerClassification::COMPANY);
        [$first, $last] = $this->splitName($name);
        $first = isset($o['firstName']) ? (string) $o['firstName'] : $first;
        $last = isset($o['lastName']) ? (string) $o['lastName'] : $last;

        return match ($type) {
            AworkCustomerClassification::IGNORE => new AworkCustomerClassification(AworkCustomerClassification::IGNORE),
            AworkCustomerClassification::PERSON => new AworkCustomerClassification(AworkCustomerClassification::PERSON, null, $first, $last),
            AworkCustomerClassification::CONTACT => new AworkCustomerClassification(
                AworkCustomerClassification::CONTACT,
                (string) ($o['company'] ?? ($industry !== '' ? $industry : $name)),
                $first,
                $last,
            ),
            default => new AworkCustomerClassification(
                AworkCustomerClassification::COMPANY,
                (string) ($o['company'] ?? $name),
            ),
        };
    }

    /** @return array{0: string, 1: string} [firstName, lastName] */
    public function splitName(string $name): array
    {
        $name = trim($name);
        if (str_contains($name, ',')) {
            [$last, $first] = array_pad(explode(',', $name, 2), 2, '');

            return [trim($first), trim($last)];
        }
        $tokens = preg_split('/\s+/', $name) ?: [];
        if (\count($tokens) <= 1) {
            return ['', $name];
        }
        $last = (string) array_pop($tokens);

        return [implode(' ', $tokens), $last];
    }

    private function looksLikePerson(string $name): bool
    {
        $n = trim($name);
        if ($n === '') {
            return false;
        }
        if (preg_match('/\b(GmbH|AG|KG|mbH|UG|gGmbH|GbR|OHG|SE|Ltd|Inc|Co|Stiftung|Institut|Verein|Stadt|Gemeinde|Kanzlei|Container|System|Systems|Consulting|Akademie|Group|Holding|Software|Marketing|Media|Agentur|Bau|Werk|RWTH|Uni|Hochschule)\b/i', $n)) {
            return false;
        }
        if (preg_match('/e\.?\s?V\.?/i', $n) || preg_match('/\.(de|com|net|org|eu)\b/i', $n) || preg_match('/\d/', $n)) {
            return false;
        }
        $tokens = preg_split('/\s+/', $n) ?: [];

        return \count($tokens) === 2
            && preg_match('/^\p{Lu}[\p{L}\'\-]+$/u', $tokens[0]) === 1
            && preg_match('/^\p{Lu}[\p{L}\'\-]+$/u', $tokens[1]) === 1;
    }
}
