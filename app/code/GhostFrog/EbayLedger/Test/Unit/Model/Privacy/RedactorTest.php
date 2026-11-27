<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\Privacy;

use GhostFrog\EbayLedger\Model\Ebay\Xml;
use GhostFrog\EbayLedger\Model\Privacy\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function testRemovesPersonalDetailsAndKeepsIdsAndAmounts(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../_files/get_orders_buyer.xml');
        $order = Xml::list(Xml::toArray($xml)['OrderArray']['Order'])[0];
        $json = json_encode(Redactor::clean($order));

        foreach (['Somebody', 'High St', 'AB1 2CD', 'switch_seller_99', 'example.com', 'UserFirstName'] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }
        foreach (['12-34567-89012', '256789012345', '186.07', 'Nintendo Switch OLED'] as $kept) {
            $this->assertStringContainsString($kept, $json);
        }
    }
}
