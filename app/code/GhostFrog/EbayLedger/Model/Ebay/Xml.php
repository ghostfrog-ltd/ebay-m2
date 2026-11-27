<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Ebay;

use SimpleXMLElement;

/** Turns Trading API XML into plain arrays. */
final class Xml
{
    /**
     * Leaf elements become strings, or ['value' => ..., '@attr' => ...] when they carry attributes such as
     * currencyID. Elements that repeat become lists.
     */
    public static function toArray(SimpleXMLElement $element): array|string
    {
        $children = $element->children();
        $attributes = [];
        foreach ($element->attributes() as $name => $value) {
            $attributes['@' . $name] = (string)$value;
        }
        if ($children->count() === 0) {
            $value = (string)$element;
            return $attributes === [] ? $value : ['value' => $value] + $attributes;
        }
        $result = $attributes;
        foreach ($children as $name => $child) {
            $value = self::toArray($child);
            if (!array_key_exists($name, $result)) {
                $result[$name] = $value;
            } elseif (is_array($result[$name]) && array_is_list($result[$name])) {
                $result[$name][] = $value;
            } else {
                $result[$name] = [$result[$name], $value];
            }
        }
        return $result;
    }

    /** A child that may be one element or a list of them, always as a list. */
    public static function list(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    public static function amount(mixed $value): ?float
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }
        return $value === null || $value === '' || !is_numeric($value) ? null : (float)$value;
    }

    public static function currency(mixed $value): ?string
    {
        return is_array($value) ? ($value['@currencyID'] ?? null) : null;
    }

    public static function text(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }
        $value = is_string($value) ? trim($value) : null;
        return $value === '' ? null : $value;
    }
}
