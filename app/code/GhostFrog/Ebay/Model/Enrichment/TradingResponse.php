<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Enrichment;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GhostFrog\Ebay\Model\FinalPrice\Outcome;
use RuntimeException;

final class TradingResponse
{
    public function parse(string $xml, string $itemId, bool $saleOutcome = false): array
    {
        $document = new DOMDocument();
        if ($xml === '' || !$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
            || $document->doctype !== null || $document->documentElement?->localName !== 'GetItemResponse'
        ) {
            throw new RuntimeException('Invalid eBay GetItem XML.');
        }

        $xpath = new DOMXPath($document);
        $root = $document->documentElement;
        $ack = $this->text($xpath, $root, 'Ack');
        if (!in_array($ack, ['Success', 'Warning', 'PartialFailure'], true)) {
            $codes = [];
            foreach ($xpath->query('./*[local-name()="Errors"]', $root) as $error) {
                $codes[] = (int)$this->text($xpath, $error, 'ErrorCode');
            }
            if (array_intersect($codes, [518, 21919144])) {
                throw new RuntimeException('eBay Trading API quota reached.', 429);
            }
            if (array_intersect($codes, [931, 932, 16110, 21917053])) {
                throw new RuntimeException('eBay rejected the Trading user token.', 401);
            }
            if ($saleOutcome && $codes !== [] && array_diff($codes, [17, 1505, 21920397]) === []) {
                return ['status' => 'unavailable', 'reason' => 'trading_item_unavailable'];
            }
            if (in_array(17, $codes, true)) {
                if ($saleOutcome) {
                    throw new RuntimeException('eBay returned conflicting GetItem errors.');
                }
                return ['status' => 'unavailable', 'fields' => [], 'raw' => []];
            }
            throw new RuntimeException('eBay GetItem failed.');
        }
        $item = $xpath->query('./*[local-name()="Item"]', $root)->item(0);
        if (!$item instanceof DOMElement || $this->text($xpath, $item, 'ItemID') !== $itemId) {
            throw new RuntimeException('eBay GetItem returned a different or missing item.');
        }
        return $saleOutcome ? Outcome::parse($xpath, $item) : $this->attributes($xpath, $item);
    }

    private function attributes(DOMXPath $xpath, DOMElement $item): array
    {
        $raw = [];
        $fields = [];
        $storage = [];
        $product = $xpath->query('./*[local-name()="ProductListingDetails"]', $item)->item(0);
        if ($product instanceof DOMElement) {
            $epid = $this->text($xpath, $product, 'ProductReferenceID') ?: $this->text($xpath, $product, 'ProductID');
            if ($epid !== '') {
                $raw['epid'] = $fields['epid'] = $epid;
            }
            $brand = $xpath->evaluate('string(./*[local-name()="BrandMPN"]/*[local-name()="Brand"])', $product);
            if (trim($brand) !== '') {
                $raw['Brand'] = $fields['brand'] = trim($brand);
            }
        }

        foreach ($xpath->query('./*[local-name()="ItemSpecifics"]/*[local-name()="NameValueList"]', $item) as $detail) {
            $name = $this->text($xpath, $detail, 'Name');
            $values = [];
            foreach ($xpath->query('./*[local-name()="Value"]', $detail) as $value) {
                if (trim($value->textContent) !== '') {
                    $values[] = mb_substr(trim($value->textContent), 0, 4000);
                }
            }
            if ($name === '' || str_starts_with($name, '_') || $values === []) {
                continue;
            }
            $raw[$name] = count($values) === 1 ? $values[0] : $values;
            $key = match (strtolower($name)) {
                'brand' => 'brand',
                'model' => 'model_name',
                'colour', 'color' => 'colour',
                default => null,
            };
            if ($key !== null && count($values) === 1) {
                $fields[$key] ??= $values[0];
            }
            if (in_array(strtolower($name), ['storage capacity', 'storage', 'ssd capacity', 'hard drive capacity'], true)) {
                foreach ($values as $value) {
                    $capacity = $this->storageGb($value);
                    if ($capacity !== null) {
                        $storage[$capacity] = true;
                    }
                }
            }
        }

        if (count($storage) === 1) {
            $fields['storage_gb'] = array_key_first($storage);
        }
        foreach (['ConditionID', 'ConditionDisplayName', 'ConditionDescription'] as $name) {
            $value = $this->text($xpath, $item, $name);
            if ($value !== '') {
                $raw['Trading ' . $name] = mb_substr($value, 0, 4000);
            }
        }
        foreach (['CategoryID', 'CategoryName'] as $name) {
            $value = trim($xpath->evaluate('string(./*[local-name()="PrimaryCategory"]/*[local-name()="' . $name . '"])', $item));
            if ($value !== '') {
                $raw['Trading Primary' . $name] = mb_substr($value, 0, 1000);
            }
        }
        $description = html_entity_decode($this->text($xpath, $item, 'Description'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($description !== '') {
            $description = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $description);
            $plain = trim(preg_replace('~\s+~u', ' ', strip_tags((string)$description)) ?? '');
            if ($plain !== '') {
                $raw['Trading Description'] = mb_substr($plain, 0, 4000);
            }
        }
        $photos = [];
        foreach ($xpath->query('./*[local-name()="PictureDetails"]/*[local-name()="PictureURL"]', $item) as $photo) {
            $url = trim($photo->textContent);
            if ($url !== '' && !in_array($url, $photos, true)) {
                $photos[] = $url;
                if (count($photos) === 12) {
                    break;
                }
            }
        }
        if ($photos !== []) {
            $raw['Trading imageUrls'] = $photos;
        }
        return ['status' => $raw === [] ? 'no_attrs' : 'ok', 'fields' => $fields, 'raw' => $raw];
    }

    private function text(DOMXPath $xpath, DOMElement $node, string $name): string
    {
        return trim($xpath->evaluate('string(./*[local-name()="' . $name . '"])', $node));
    }

    private function storageGb(string $text): ?int
    {
        if (preg_match('/\A\d{1,6}\z/', $text)) {
            $gb = (int)$text;
        } else {
            $count = preg_match_all('/(?<![a-z0-9])(\d+(?:[.,]\d+)?)\s*(tb|terabytes?|gb|gigabytes?|mb|megabytes?)\b/i', $text, $matches, PREG_SET_ORDER);
            if ($count !== 1) {
                return null;
            }
            [, $value, $unit] = $matches[0];
            $value = (float)str_replace(',', '.', $value);
            $gb = match (strtolower(substr($unit, 0, 1))) {
                't' => $value * 1024,
                'm' => $value / 1024,
                default => $value,
            };
        }
        return $gb > 0 && $gb <= 65536 ? max(1, (int)round($gb, 0, PHP_ROUND_HALF_EVEN)) : null;
    }
}
