<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\Collection;

use DateTimeImmutable;
use GhostFrog\Ebay\Model\Collection\MysqlListings;
use GhostFrog\Ebay\Model\Condition\Settings;
use GhostFrog\Ebay\Model\Enrichment\Queue;
use GhostFrog\Ebay\Model\ModelKey\AppleDevices;
use GhostFrog\Ebay\Model\ModelKey\AppleMac;
use GhostFrog\Ebay\Model\ModelKey\Classifier;
use GhostFrog\Ebay\Model\ModelKey\Pending;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\MysqlMq\Model\QueueManagement;
use Magento\MysqlMq\Model\ResourceModel\Queue as MessageResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Collection keys a listing from its search result so it can be valued before the checks finish. */
final class EarlyKeyTest extends TestCase
{
    private const IPHONE = 'Apple iPhone 15 Pro Max 256GB Natural Titanium';

    public function testPendingMarkIsReadFromStoredAndDecodedAttrs(): void
    {
        self::assertTrue(Pending::is('{"_checks_pending":true}'));
        self::assertTrue(Pending::is(['_checks_pending' => true]));
        self::assertTrue(Pending::is((object)['_checks_pending' => true]));
        self::assertFalse(Pending::is('{"Brand":"Apple"}'));
        self::assertFalse(Pending::is('{"_checks_pending":false}'));
        self::assertFalse(Pending::is(null));
        self::assertFalse(Pending::is('not json'));
    }

    public function testStoredListingKeyMatchesTheClassifierOnDecodedEvidence(): void
    {
        $classifier = new Classifier(new AppleMac(), new AppleDevices());
        $attrs = ['SSD Capacity' => '512 GB'];
        $title = 'MacBook Air M2 A2681 8GB RAM 256GB SSD';

        // Item specifics that contradict the title make the identity unsafe, whichever form they arrive in.
        self::assertSame('unknown', $classifier->forListing('ebay-apple', [
            'title' => $title, 'raw_attrs' => json_encode($attrs),
        ]));
        self::assertSame(
            $classifier->key('ebay-apple', self::IPHONE, ['storage_gb' => 256]),
            $classifier->forListing('ebay-apple', ['title' => self::IPHONE, 'raw_attrs' => null, 'storage_gb' => 256])
        );
    }

    public function testNewListingIsKeyedFromItsTitleAndMarkedPending(): void
    {
        $db = $this->connection(false);
        $inserted = null;
        $db->method('insert')->willReturnCallback(function (string $table, array $row) use (&$inserted): int {
            if ($table === 'ghostfrog_ebay_auction_listings') {
                $inserted = $row;
            }
            return 1;
        });

        $this->save($db, self::IPHONE);

        self::assertSame('apple-iphone-15-pro-max-256gb', $inserted['model_key']);
        self::assertTrue(Pending::is($inserted['raw_attrs']));
        self::assertSame('Used', json_decode($inserted['raw_attrs'], true)['Browse condition']);
    }

    public function testUnidentifiableListingIsLeftForTheFullPipeline(): void
    {
        $db = $this->connection(false);
        $inserted = null;
        $db->method('insert')->willReturnCallback(function (string $table, array $row) use (&$inserted): int {
            if ($table === 'ghostfrog_ebay_auction_listings') {
                $inserted = $row;
            }
            return 1;
        });

        $this->save($db, 'Apple bundle of bits');

        self::assertArrayNotHasKey('model_key', $inserted);
        self::assertFalse(Pending::is($inserted['raw_attrs']));
    }

    public function testNeverClassifiedListingIsKeyedWhenSeenAgain(): void
    {
        $db = $this->connection($this->existing(null));
        $db->expects(self::atLeastOnce())->method('update')->willReturnCallback(
            function (string $table, array $row): int {
                if ($table === 'ghostfrog_ebay_auction_listings' && isset($row['title'])) {
                    self::assertSame('apple-iphone-15-pro-max-256gb', $row['model_key']);
                    self::assertTrue(Pending::is($row['raw_attrs']));
                    // Evidence stored by earlier steps survives the refresh.
                    self::assertSame('kept', json_decode($row['raw_attrs'], true)['Earlier']);
                }
                return 1;
            }
        );

        $this->save($db, self::IPHONE);
    }

    public function testFinishedUnknownKeyIsNotReplacedFromTheTitle(): void
    {
        $db = $this->connection($this->existing('unknown'));
        $db->expects(self::atLeastOnce())->method('update')->willReturnCallback(
            function (string $table, array $row): int {
                if ($table === 'ghostfrog_ebay_auction_listings' && isset($row['title'])) {
                    self::assertArrayNotHasKey('model_key', $row);
                    self::assertFalse(Pending::is($row['raw_attrs']));
                }
                return 1;
            }
        );

        $this->save($db, self::IPHONE);
    }

    private function existing(?string $key): array
    {
        return [
            'id' => '7', 'status' => 'live', 'finalized' => '0', 'last_seen_at' => '2026-11-27 07:00:00',
            'raw_attrs' => '{"Earlier":"kept"}', 'model_key' => $key,
        ];
    }

    private function save(AdapterInterface $db, string $title): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $classifier = new Classifier(new AppleMac(), new AppleDevices());
        $queue = new Queue(
            $resource,
            $this->createMock(QueueManagement::class),
            new Settings($this->createMock(ScopeConfigInterface::class)),
            $this->createMock(MessageResource::class),
            $classifier
        );
        $listings = new MysqlListings($resource, $this->createMock(LockManagerInterface::class), $queue, $classifier);
        $now = new DateTimeImmutable('2026-11-27 08:00:00 UTC');

        $listings->save(['id' => 6, 'name' => 'ebay-apple', 'domain' => 'ebay-apple'], ['v1|1|0' => [
            'title' => $title,
            'price_current' => '234.00',
            'bids_count' => 0,
            'end_time' => null,
            'url' => 'https://www.ebay.co.uk/itm/1',
            'sale_type' => 'bin',
            'time_left_s' => null,
            'raw_attrs' => '{"Browse condition":"Used"}',
            'price_bid_current' => null,
            'price_bin_current' => '234.00',
        ]], [], $now, $now->modify('-1 day'));
    }

    /** @param array|false $existing the stored row for the listing, or false when it is new */
    private function connection(array|false $existing): AdapterInterface&MockObject
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'forUpdate', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $decimal = ['DATA_TYPE' => 'decimal', 'SCALE' => 2];
        // The interface has no lastInsertId(), which the collector uses to queue a new listing.
        $db = $this->createMock(Mysql::class);
        $db->method('select')->willReturn($select);
        $db->method('describeTable')->willReturn([
            'price_current' => $decimal, 'price_bid_current' => $decimal, 'price' => $decimal,
        ]);
        // The source is enabled and the enrichment queue is already registered.
        $db->method('fetchOne')->willReturn('1');
        $db->method('fetchCol')->willReturn(['live', 'unknown']);
        $db->method('fetchRow')->willReturn($existing);
        $db->method('lastInsertId')->willReturn('7');
        return $db;
    }
}
