<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Condition;

use RuntimeException;

final class Assessment
{
    private const GRADES = ['A', 'B', 'C', 'D', 'unknown'];
    private const TEXT_SEVERE = [
        'accessory_only', 'functional_fault', 'locked_device', 'missing_major_part',
        'parts_or_repair', 'vague_or_untested', 'water_damage',
    ];
    private const TEXT_DAMAGE = ['battery_fault', 'casing_damage', 'screen_damage'];
    private const VISUAL_SEVERE = [
        'activation_lock_or_error_screen', 'bent_frame', 'missing_parts', 'water_damage_signs',
    ];
    private const VISUAL_DAMAGE = [
        'camera_lens_damage', 'chipped_corner', 'cracked_back_glass', 'cracked_screen',
        'deep_scratches', 'heavy_wear',
    ];

    private static function flags(Stage $stage): array
    {
        return match ($stage) {
            Stage::Text => array_merge(self::TEXT_SEVERE, self::TEXT_DAMAGE, ['contradiction', 'seller_risk']),
            Stage::Visual => array_merge(self::VISUAL_SEVERE, self::VISUAL_DAMAGE),
        };
    }

    public static function schema(Stage $stage): array
    {
        return [
            'type' => 'object',
            'properties' => [
                $stage->gradeField() => ['type' => 'string', 'enum' => self::GRADES],
                $stage->flagsField() => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::flags($stage)]],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'review_required' => ['type' => 'boolean'],
                'notes' => ['type' => 'string'],
            ],
            'required' => [$stage->gradeField(), $stage->flagsField(), 'confidence', 'review_required', 'notes'],
            'additionalProperties' => false,
        ];
    }

    public static function normalise(Stage $stage, array $raw): array
    {
        $grade = $raw[$stage->gradeField()] ?? null;
        $risk = $raw[$stage->flagsField()] ?? null;
        $confidence = $raw['confidence'] ?? null;
        $review = $raw['review_required'] ?? null;
        $notes = $raw['notes'] ?? null;
        if (!in_array($grade, self::GRADES, true) || !is_array($risk) || !array_is_list($risk)
            || (!is_float($confidence) && !is_int($confidence)) || !is_bool($review) || !is_string($notes)
        ) {
            throw new RuntimeException('Ollama returned an invalid condition assessment.');
        }

        $flags = array_values(array_unique(array_filter($risk,
            static fn($flag): bool => in_array($flag, self::flags($stage), true))));
        $visual = $stage === Stage::Visual;
        if (array_intersect($flags, $visual ? self::VISUAL_SEVERE : self::TEXT_SEVERE)) {
            $grade = 'D';
        } elseif (in_array($grade, ['A', 'B'], true) && array_intersect($flags, $visual ? self::VISUAL_DAMAGE : self::TEXT_DAMAGE)) {
            $grade = 'C';
        }

        $confidence = max(0.0, min(1.0, (float)$confidence));
        $review = $review || $confidence < 0.7 || in_array($grade, ['unknown', 'D'], true)
            || ($visual && $grade === 'C') || in_array('contradiction', $flags, true)
            || in_array('seller_risk', $flags, true);
        return [
            $stage->gradeField() => $grade,
            $stage->flagsField() => $flags,
            'confidence' => $confidence,
            'review_required' => $review,
            'notes' => mb_substr(trim($notes), 0, 500),
        ];
    }

    public static function missing(Stage $stage, string $notes): array
    {
        return self::normalise($stage, [
            $stage->gradeField() => 'unknown',
            $stage->flagsField() => [],
            'confidence' => 0,
            'review_required' => true,
            'notes' => $notes,
        ]);
    }

    /**
     * A review-required result keeps a listing out of clean grades. Definite damage
     * or faults cap the result even when another assessment has poor evidence.
     */
    public static function effective(?string $stored, array $checks): ?string
    {
        $rank = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
        $worst = isset($rank[$stored]) ? $stored : null;
        $review = false;
        foreach ($checks as $check) {
            $review = $review || $check['review_required'];
            $grade = $check['grade'];
            if (isset($rank[$grade]) && ($worst === null || $rank[$grade] > $rank[$worst])) {
                $worst = $grade;
            }
        }
        if ($review && ($worst === null || $rank[$worst] < $rank['C'])) {
            return 'unknown';
        }
        return $worst ?? $stored;
    }
}
