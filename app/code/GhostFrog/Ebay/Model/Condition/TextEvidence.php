<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Condition;

/** Picks the description, condition and specifics text out of a listing's raw attributes for an LLM check. */
final class TextEvidence
{
    private const FIELDS = '~\A(?:Browse (?:condition|description|shortDescription|subtitle|aspect )|'
        . 'Trading (?:ConditionDescription|ConditionDisplayName|Description)|'
        . '(?:Condition|Condition Description|Description|Product Condition|Grade|Product Grade|'
        . 'Network Lock|Lock Status|Operation|Defects|Features)\z)~i';

    /** @return array<string, string> Field name => plain text, at most 10,000 characters in all. */
    public static function fromAttrs(array $attrs): array
    {
        $result = [];
        $remaining = 10_000;
        foreach ($attrs as $name => $value) {
            if (!preg_match(self::FIELDS, (string)$name)) {
                continue;
            }
            $values = array_filter((array)$value, static fn($item) => is_scalar($item));
            $plain = trim(implode(', ', $values));
            $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($plain !== '') {
                $plain = mb_substr($plain, 0, min($remaining, 4000));
                $result[(string)$name] = $plain;
                $remaining -= mb_strlen($plain);
            }
            if ($remaining === 0 || count($result) >= 40) {
                break;
            }
        }
        return $result;
    }
}
