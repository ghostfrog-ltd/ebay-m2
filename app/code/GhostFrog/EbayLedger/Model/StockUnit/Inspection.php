<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\StockUnit;

use GhostFrog\EbayLedger\Model\Purchase\OrderParser;
use InvalidArgumentException;

/**
 * Turns the Receive & Inspect form into changes on a stock unit: what arrived, its real grade, and whether you
 * kept it, sent it back or wrote it off. Units already planned, listed or sold keep their status.
 */
final class Inspection
{
    /** Statuses the receive form may move a unit between. */
    private const MOVABLE = [
        Status::PURCHASED, Status::IN_TRANSIT, Status::RECEIVED, Status::RETURNED_TO_SELLER, Status::WRITTEN_OFF,
    ];
    private const METHODS = [
        OrderParser::METHOD_AUCTION, OrderParser::METHOD_BUY_IT_NOW, OrderParser::METHOD_BEST_OFFER,
        OrderParser::METHOD_UNKNOWN,
    ];

    /**
     * @param array $unit The stored stock unit row.
     * @param array $input received_at, actual_grade, contents (text, one part per line), faults, notes,
     *     photos (list of file names), outcome, refund_amount, refunded_at, purchase_method.
     * @return array<string, mixed> Columns to save.
     * @throws InvalidArgumentException When the input does not make sense.
     */
    public static function apply(array $unit, array $input): array
    {
        $receivedAt = self::date($input['received_at'] ?? null, 'date received');
        $grade = strtoupper(trim((string)($input['actual_grade'] ?? '')));
        if ($grade !== '' && !isset(Grade::LABELS[$grade])) {
            throw new InvalidArgumentException('Choose a grade from the list.');
        }
        $refund = round((float)str_replace(['£', ','], '', (string)($input['refund_amount'] ?? '0')), 2);
        if ($refund < 0 || $refund > (float)$unit['total_cost'] + 0.001) {
            throw new InvalidArgumentException(sprintf('A refund must be between £0.00 and what you paid, £%.2f.',
                (float)$unit['total_cost']));
        }
        $refundedAt = self::date($input['refunded_at'] ?? null, 'refund date');
        if ($refund > 0 && $refundedAt === null) {
            $refundedAt = gmdate('Y-m-d');
        }
        $outcome = (string)($input['outcome'] ?? Outcome::KEEP);
        if (!in_array($outcome, [Outcome::KEEP, Status::RETURNED_TO_SELLER, Status::WRITTEN_OFF], true)) {
            throw new InvalidArgumentException('Choose what happens to the item.');
        }

        $contents = array_values(array_filter(array_map('trim',
            preg_split('/\R/', (string)($input['contents'] ?? '')) ?: [])));
        $photos = array_values(array_filter(array_map(
            static fn($photo) => is_array($photo) ? (string)($photo['file'] ?? $photo['name'] ?? '') : (string)$photo,
            (array)($input['photos'] ?? [])
        ), static fn(string $file) => $file !== '' && !str_contains($file, '..')));

        $changes = [
            'received_at' => $receivedAt,
            'actual_grade' => $grade === '' ? null : $grade,
            'contents' => $contents === [] ? null : json_encode($contents, JSON_UNESCAPED_UNICODE),
            'faults' => self::text($input['faults'] ?? null),
            'notes' => self::text($input['notes'] ?? null),
            'photos' => $photos === [] ? null : json_encode($photos, JSON_UNESCAPED_SLASHES),
            'refund_amount' => $refund,
            'refunded_at' => $refund > 0 ? $refundedAt : null,
            'inspected_at' => gmdate('Y-m-d H:i:s'),
        ];

        $method = (string)($input['purchase_method'] ?? $unit['purchase_method']);
        if ($method !== $unit['purchase_method']) {
            if (!in_array($method, self::METHODS, true)) {
                throw new InvalidArgumentException('Choose how the item was bought from the list.');
            }
            $changes['purchase_method'] = $method;
            $changes['purchase_method_source'] = 'manual';
        }

        if (in_array($unit['status'], self::MOVABLE, true)) {
            $changes['status'] = match (true) {
                $outcome !== Outcome::KEEP => $outcome,
                $receivedAt !== null => Status::RECEIVED,
                !empty($unit['shipped_at']) => Status::IN_TRANSIT,
                default => Status::PURCHASED,
            };
        } elseif ($outcome !== Outcome::KEEP) {
            throw new InvalidArgumentException(sprintf('This unit is already %s, so it can no longer be returned or written off here.',
                strtolower(Status::LABELS[$unit['status']] ?? $unit['status'])));
        }
        return $changes;
    }

    private static function date(mixed $value, string $label): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        // The admin date picker posts the locale's format, such as 27/11/2026 or 11/27/2026.
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y-m-d H:i:s', 'd/m/y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
            if ($date !== false && $date->format($format) === $value) {
                if ($date->getTimestamp() > time() + 86400) {
                    throw new InvalidArgumentException("The $label cannot be in the future.");
                }
                return $date->format('Y-m-d');
            }
        }
        throw new InvalidArgumentException("The $label is not a date.");
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : mb_substr($value, 0, 10000);
    }
}
