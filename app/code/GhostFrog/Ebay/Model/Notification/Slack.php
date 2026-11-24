<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Notification;

use Magento\Framework\HTTP\Client\CurlFactory;

final class Slack
{
    public function __construct(private readonly CurlFactory $clients)
    {
    }

    public function send(string $url, string $type, array $offer): array
    {
        $client = $this->clients->create();
        $client->setTimeout(5);
        $client->setHeaders(['Content-Type' => 'application/json']);
        $client->post($url, json_encode($this->message($type, $offer), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $status = (int)$client->getStatus();
        $retryAfter = array_change_key_case($client->getHeaders())['retry-after'] ?? '';
        return [$status, ctype_digit((string)$retryAfter) ? max(1, (int)$retryAfter) : null];
    }

    private function message(string $type, array $offer): array
    {
        $parts = explode('|', $offer['external_id']);
        $link = 'https://www.ebay.co.uk/itm/' . rawurlencode($parts[1] ?? $parts[0]);
        if (!empty($parts[2])) {
            $link .= '?var=' . rawurlencode($parts[2]);
        }
        $auction = $type === 'auction';
        $title = strtr((string)$offer['title'], ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
        $text = implode("\n", [
            $title,
            $link,
            $auction ? 'Auction (indicative, final price may be higher)' : 'Buy It Now',
            sprintf('%s £%.2f', $auction ? 'Current auction price / bid' : 'Current price', $offer['offer_price']),
            sprintf('Maximum safe purchase price £%.2f', $offer['max_safe_buy']),
            sprintf('Expected profit £%.2f', $offer['expected_profit']),
            sprintf('Comparable sales %d', $offer['comp_count']),
            'Last price checked ' . $offer['last_price_checked_at'] . ' UTC',
            'End time ' . ($offer['end_time'] === null ? 'unlisted' : $offer['end_time'] . ' UTC'),
        ]);
        return ['text' => $text, 'mrkdwn' => false, 'unfurl_links' => false, 'unfurl_media' => false];
    }
}
