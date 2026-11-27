<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Ebay;

use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

/** Which eBay account a user token belongs to (Commerce Identity API). */
class Identity
{
    private const URL = 'https://apiz.ebay.com/commerce/identity/v1/user/';

    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    /** @return array{username: ?string, user_id: ?string} */
    public function user(string $accessToken): array
    {
        $client = $this->curlFactory->create();
        $client->setTimeout(15);
        $client->setHeaders(['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']);
        $client->get(self::URL);
        $status = (int)$client->getStatus();
        $payload = json_decode((string)$client->getBody(), true);
        if ($status !== 200 || !is_array($payload)) {
            throw new RuntimeException(sprintf('eBay Identity returned HTTP %d.', $status), $status);
        }
        return [
            'username' => isset($payload['username']) ? (string)$payload['username'] : null,
            'user_id' => isset($payload['userId']) ? (string)$payload['userId'] : null,
        ];
    }
}
