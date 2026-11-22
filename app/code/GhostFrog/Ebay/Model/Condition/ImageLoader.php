<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Condition;

use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

final class ImageLoader
{
    private const LIMIT = 6;
    private const MAX_BYTES = 7_000_000;

    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    public function load(array $attrs): array
    {
        $urls = array_merge(
            (array)($attrs['Trading imageUrls'] ?? []),
            (array)($attrs['Browse imageUrls'] ?? $attrs['Browse imageUrl'] ?? []),
            (array)($attrs['image_urls'] ?? $attrs['imageUrls'] ?? [])
        );
        $encoded = [];
        $used = [];
        $seen = [];
        $transient = false;
        foreach ($urls as $url) {
            if (!is_string($url) || isset($seen[$url]) || !$this->allowed($url)) {
                continue;
            }
            $seen[$url] = true;
            try {
                $image = $this->download($url);
            } catch (RuntimeException) {
                $transient = true;
                continue;
            }
            if ($image !== null) {
                $encoded[] = $image;
                $used[] = $url;
            }
            if (count($seen) === self::LIMIT) {
                break;
            }
        }
        if ($encoded === [] && $transient) {
            throw new RuntimeException('Listing photos could not be downloaded.');
        }
        return ['urls' => $used, 'images' => $encoded];
    }

    private function allowed(string $url): bool
    {
        $parts = parse_url($url);
        return $parts && ($parts['scheme'] ?? '') === 'https'
            && preg_match('~\Ai[0-9]*\.ebayimg\.com\z~i', $parts['host'] ?? '') === 1
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']);
    }

    private function download(string $url): ?string
    {
        $client = $this->curlFactory->create();
        $body = '';
        $tooLarge = false;
        $client->setTimeout(10);
        $client->setOptions([
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $client->get($url);
        } catch (\Exception) {
            if ($tooLarge) {
                return null;
            }
            throw new RuntimeException('eBay photo download failed.');
        }

        $status = (int)$client->getStatus();
        if ($status === 429 || $status >= 500) {
            throw new RuntimeException('eBay photo service temporarily unavailable.');
        }
        $type = $body === '' ? false : @getimagesizefromstring($body);
        if ($status !== 200 || $type === false || $type[0] * $type[1] > 20_000_000
            || !in_array($type['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
        ) {
            return null;
        }
        return base64_encode($body);
    }
}
