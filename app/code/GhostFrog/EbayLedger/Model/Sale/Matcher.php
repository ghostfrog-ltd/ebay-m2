<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Sale;

/**
 * Matches a sale line to a Ledger product by the listing's custom label (SKU), exactly. It never guesses: no SKU,
 * an unknown SKU, a product already sold, or more than one item on the line leaves the sale unmatched.
 */
final class Matcher
{
    /**
     * @param array<string, array> $products Ledger product rows keyed by SKU (status active or sold).
     * @param array<int, string> $claimed Ledger product ID => eBay line already matched to it.
     * @return array{match_status: string, ledger_product_id: ?int, match_note: ?string}
     */
    public static function match(array $line, array $products, array $claimed): array
    {
        $unmatched = static fn(string $note) => ['match_status' => 'unmatched', 'ledger_product_id' => null, 'match_note' => $note];
        if ($line['sku'] === null) {
            return $unmatched('The eBay listing has no custom label (SKU).');
        }
        $product = $products[$line['sku']] ?? null;
        if ($product === null) {
            return $unmatched(sprintf('No Ledger product has SKU %s.', $line['sku']));
        }
        $owner = $claimed[(int)$product['ledger_product_id']] ?? null;
        if ($owner !== null && $owner !== $line['ebay_order_id'] . '/' . $line['ebay_line_item_id']) {
            return $unmatched(sprintf('%s is already matched to another sale.', $line['sku']));
        }
        if ((int)$line['quantity'] !== 1) {
            return $unmatched(sprintf('%d items sold on one line; each Ledger product is a single item.', $line['quantity']));
        }
        return ['match_status' => 'matched', 'ledger_product_id' => (int)$product['ledger_product_id'], 'match_note' => null];
    }
}
