<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use GhostFrog\Ebay\Model\Condition\Assessment;
use GhostFrog\Ebay\Model\Condition\Stage;
use GhostFrog\Ebay\Model\ModelKey\Classifier;

/**
 * Puts a listing in the comparable group it can be valued against, or none.
 *
 * Working listings go to their model key and effective grade A, B or C. Listings whose title says they are
 * faulty, for parts or untested, or that eBay lists as "For parts or not working", go to the same model key
 * with grade F, so they are only ever compared with other faulty sales and never move a working median.
 * Fault wording found only in the seller's description is too loose to call either way (refurbishers' grading
 * notes mention dead pixels, sellers write "no cracked screen"), so those listings stay out of both groups. Things that are not the whole item (boxes, accessories,
 * parts of a device), job lots and locked devices are in no group at all.
 */
final class Eligibility
{
    public const FAULTY_GRADE = 'F';

    private const FAULTY = '~\b(?:for parts|parts (?:only|missing|(?:or|and) repair)|for spares|spares only|'
        . 'spares\s*(?:(?:or|and|[&/])\s*)?repairs?|(?:for|needs?) repairs?|repair only|'
        . 'faulty|not (?:fully )?working|not starting|dead|scraps?|untested|as[ -]is|grade d|'
        . '(?:cracked|broken|damaged) (?:lcd|display|screen)|(?:screen|touch ?id|mic(?:rophone)?) (?:issue|damage))\b~i';

    private const EXCLUDED = '~\b(?:empty (?:original )?box|box (?:and|&) inserts|'
        . '(?:box|packaging|manual|instructions|stickers|charger|case|housing|shell|cable|adapter|battery|screen) only|'
        . 'replacement (?:part|screen|keyboard|battery|shell|case)|logic ?board|screen (?:for|assembly)|'
        . '(?:carrying|protective|travel) cases?|screen protector|usb[ -]?[ac]?(?: to usb[ -]?[ac])? cables?|'
        . '(?:case|cover|charger|cable|dock|keyboard|battery) for|(?:lcd|display|keyboard) assembly|'
        . 'job\s*lot|unit only|no accessories|activation lock(?:ed)?|'
        . '(?:icloud|mdm|bios|password|carrier|network|account)[ -]locked|blacklisted)\b~i';

    public function __construct(private readonly Classifier $classifier)
    {
    }

    public function group(array $listing): ?array
    {
        $key = strtolower(trim((string)$listing['model_key']));
        $title = (string)$listing['title'];
        $attrs = json_decode((string)$listing['raw_attrs'], true);
        $attrs = is_array($attrs) ? $attrs : [];
        $source = (string)(($listing['source_domain'] ?? null) ?: $listing['source']);
        $fields = array_intersect_key($listing, array_flip(['brand', 'model_name', 'storage_gb', 'colour', 'epid']));

        $evidence = $title . ' ' . (string)$listing['raw_attrs'];
        if ($key === '' || str_starts_with($key, 'unknown') || str_contains($key, 'generic')
            || str_contains($key, 'gen-unk') || preg_match('/_[a-d](?:-[a-d])?$/', $key)
            || ($listing['review_action'] ?? null) === 'down'
            || preg_match(self::EXCLUDED, $evidence)
        ) {
            return null;
        }

        if (preg_match(self::FAULTY, $evidence)) {
            // Condition checks are skipped: a faulty listing is expected to grade badly.
            if (!self::declaredFaulty($title, $attrs, str_starts_with($key, 'game-'))
                || $this->classifier->key($source, $title, $attrs + $fields) !== $key
            ) {
                return null;
            }
            return [$key, self::FAULTY_GRADE];
        }

        $checks = [];
        foreach (Stage::cases() as $stage) {
            if (!empty($listing[$stage->value . '_review'])) {
                return null;
            }
            $value = $listing[$stage->gradeField()] ?? null;
            if ($value !== null) {
                $checks[] = ['grade' => strtoupper(trim((string)$value)), 'review_required' => false];
            }
        }
        $stored = strtoupper(trim((string)$listing['condition_grade']));
        if ($stored !== '' && !in_array($stored, ['A', 'B', 'C'], true)) {
            return null;
        }
        $grade = Assessment::effective($stored ?: 'B', $checks);
        if (!in_array($grade, ['A', 'B', 'C'], true)
            || $this->classifier->key($source, $title, $attrs + $fields) !== $key
        ) {
            return null;
        }

        return [$key, $grade];
    }

    /**
     * The title says the item is faulty, or eBay lists its condition as for parts or not working.
     * In a game title "dead" is part of the name (Evil Dead, Red Dead Redemption), so it does not count.
     */
    private static function declaredFaulty(string $title, array $attrs, bool $game = false): bool
    {
        if (preg_match(self::FAULTY, $game ? preg_replace('~\bdead\b~i', '', $title) : $title)) {
            return true;
        }
        foreach (['Browse condition', 'Trading ConditionDisplayName'] as $field) {
            if (is_string($attrs[$field] ?? null) && stripos($attrs[$field], 'for parts') !== false) {
                return true;
            }
        }
        return false;
    }
}
