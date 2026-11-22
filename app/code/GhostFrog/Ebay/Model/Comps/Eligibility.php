<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use GhostFrog\Ebay\Model\Condition\Assessment;
use GhostFrog\Ebay\Model\Condition\Stage;
use GhostFrog\Ebay\Model\ModelKey\Classifier;

final class Eligibility
{
    private const BLOCKED = '~\b(?:for parts|parts (?:only|missing|(?:or|and) repair)|for spares|spares only|'
        . 'spares\s*(?:(?:or|and|[&/])\s*)?repairs?|(?:for|needs?) repairs?|repair only|'
        . 'faulty|not (?:fully )?working|not starting|dead|scraps?|untested|as[ -]is|grade d|'
        . 'empty (?:original )?box|box (?:and|&) inserts|'
        . '(?:box|packaging|manual|instructions|stickers|charger|case|housing|shell|cable|adapter|battery|screen) only|'
        . 'replacement (?:part|screen|keyboard|battery|shell|case)|logic ?board|screen (?:for|assembly)|'
        . '(?:carrying|protective|travel) cases?|screen protector|usb[ -]?[ac]?(?: to usb[ -]?[ac])? cables?|'
        . '(?:case|cover|charger|cable|dock|keyboard|battery) for|(?:lcd|display|keyboard) assembly|'
        . '(?:cracked|broken|damaged) (?:lcd|display|screen)|(?:screen|touch ?id|mic(?:rophone)?) (?:issue|damage)|'
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

        if ($key === '' || str_starts_with($key, 'unknown') || str_contains($key, 'generic')
            || str_contains($key, 'gen-unk') || preg_match('/_[a-d](?:-[a-d])?$/', $key)
            || ($listing['review_action'] ?? null) === 'down'
            || preg_match(self::BLOCKED, $title . ' ' . (string)$listing['raw_attrs'])
        ) {
            return null;
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
}
