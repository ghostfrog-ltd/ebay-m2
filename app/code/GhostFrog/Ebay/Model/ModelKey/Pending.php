<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

/**
 * Marks a listing that was keyed from its search result, before item details and condition checks ran.
 *
 * The early key lets the listing be valued within a minute of collection, and the queues take listings
 * that value as opportunities first. Title-only keys are sometimes wrong, so the mark stays in raw_attrs
 * until the model-key worker confirms the key at the end of the pipeline. While it is set the listing can
 * appear in Opportunities but cannot alert or count as a sold comparable.
 */
final class Pending
{
    public const FLAG = '_checks_pending';
    public const PATH = '$.' . self::FLAG;

    /** @param mixed $rawAttrs raw_attrs as stored JSON, a decoded array or a decoded object */
    public static function is(mixed $rawAttrs): bool
    {
        if (is_string($rawAttrs)) {
            $rawAttrs = json_decode($rawAttrs, true);
        }
        if (is_object($rawAttrs)) {
            $rawAttrs = (array)$rawAttrs;
        }
        return is_array($rawAttrs) && !empty($rawAttrs[self::FLAG]);
    }

    /** SQL condition that is true when the listing aliased $alias carries the mark. */
    public static function sql(string $alias): string
    {
        return sprintf("COALESCE(JSON_CONTAINS_PATH(%s.raw_attrs, 'one', '%s'), 0) = 1", $alias, self::PATH);
    }
}
