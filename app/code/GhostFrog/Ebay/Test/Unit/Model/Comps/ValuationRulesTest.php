<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\Comps;

use GhostFrog\Ebay\Model\Comps\Eligibility;
use GhostFrog\Ebay\Model\Comps\Pricing;
use GhostFrog\Ebay\Model\ModelKey\AppleDevices;
use GhostFrog\Ebay\Model\ModelKey\AppleMac;
use GhostFrog\Ebay\Model\ModelKey\Classifier;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValuationRulesTest extends TestCase
{
    public function testDefaultPricingRetainsTheHalfPennyMedianUntilTheEnd(): void
    {
        $pricing = new Pricing($this->createStub(ScopeConfigInterface::class));

        self::assertSame([
            'max_safe_buy' => 205.1,
            'expected_profit' => 67.79,
            'expected_roi_percent' => 36.06,
            'meets_target' => true,
            'shipping' => 12.0,
        ], $pricing->calculate(322.995, 188));
    }

    public function testPricingUsesAdminSettingsIncludingZero(): void
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(static fn(string $path): string => match ($path) {
            'ghostfrog_ebay/comps/target_profit' => '100.00',
            'ghostfrog_ebay/comps/target_roi_percent' => '0',
            'ghostfrog_ebay/comps/selling_fee_percent' => '0',
            'ghostfrog_ebay/comps/shipping' => '20.00',
        });

        self::assertSame([
            'max_safe_buy' => 263.75,
            'expected_profit' => 62.3,
            'expected_roi_percent' => 20.77,
            'meets_target' => false,
            'shipping' => 20.0,
        ], (new Pricing($config))->calculate(400, 300));
    }

    public function testRoiTargetMakesDearerBuysEarnMore(): void
    {
        $pricing = new Pricing($this->config(['target_profit' => '20', 'target_roi_percent' => '20']));

        // Net of a £700 median is 700 * 0.87 - 17 = 592. A £450 buy pays £15.70 Buyer Protection and makes
        // £126.30, over the £90 that 20% asks for. The ROI limit sets the ceiling: 1.2p + fee(p) = 592.
        $result = $pricing->calculate(700, 450);
        self::assertSame(479.75, $result['max_safe_buy']);
        self::assertTrue($result['meets_target']);

        // A £500 buy of the same item makes £75.30 after its fee, short of the £100 that 20% asks for.
        self::assertFalse($pricing->calculate(700, 500)['meets_target']);

        // A cheap buy falls back to the £20 floor: net 130.38 - 100 - 4.70 fee = 25.68 >= max(20, 20).
        self::assertTrue($pricing->calculate(169.4, 100)['meets_target']);
    }

    public function testSourceMinimumsOverrideTheGlobalTargets(): void
    {
        $pricing = new Pricing($this->config(['target_profit' => '50', 'target_roi_percent' => '0']));
        $console = ['source_min_profit' => '20.00', 'source_min_roi_percent' => '15.00'];

        self::assertSame([50.0, 0.0], $pricing->targets());
        self::assertSame([20.0, 15.0], $pricing->targets($console));
        // A null override keeps the global value for that field only.
        self::assertSame([50.0, 15.0], $pricing->targets(['source_min_profit' => null, 'source_min_roi_percent' => '15']));

        // Net of a £168 Series S median is 129.16: a £100 buy makes £29.16.
        self::assertFalse($pricing->calculate(168, 100)['meets_target']);
        self::assertTrue($pricing->calculate(168, 100, $console)['meets_target']);
    }

    public function testSourceShippingOverridesTheGlobalSetting(): void
    {
        $pricing = new Pricing($this->config(['shipping' => '12']));

        self::assertSame(12.0, $pricing->shipping());
        self::assertSame(12.0, $pricing->shipping(['source_shipping' => null]));
        self::assertSame(20.0, $pricing->shipping(['source_shipping' => '20.00']));
        // A £66.63 median nets 66.63 * 0.87 - 12 - 5 = 40.97; £8 more shipping takes it to 32.97.
        // A £14.78 buy also pays £1.13 Buyer Protection.
        self::assertSame(25.06, $pricing->calculate(66.63, 14.78)['expected_profit']);
        self::assertSame(17.06, $pricing->calculate(66.63, 14.78, ['source_shipping' => '20.00'])['expected_profit']);
    }

    public function testListingPostageIsPaidInAndAgainOut(): void
    {
        $pricing = new Pricing($this->config(['shipping' => '12']));
        $postage = static fn(array $options): array => ['raw_attrs' => json_encode(['Browse shippingOptions' => $options])];
        $paid = $postage([['shippingCost' => ['value' => '6.76', 'currency' => 'GBP']]]);

        self::assertSame(6.76, Pricing::listingPostage($paid));
        self::assertSame(13.52, $pricing->shippingFor($paid));
        // Postage cheaper than half the estimate still costs the estimate's half to send out.
        self::assertSame(8.99, $pricing->shippingFor($postage([['shippingCost' => ['value' => '2.99', 'currency' => 'GBP']]])));
        // Free postage costs nothing in, and the source's half estimate out.
        $free = $postage([['shippingCost' => ['value' => '0.00', 'currency' => 'GBP']]]);
        self::assertSame(8.0, $pricing->shippingFor($free + ['source_shipping' => '16.00']));
        // The cheapest GBP option counts; options without a cost or in another currency are ignored.
        self::assertSame(4.5, Pricing::listingPostage($postage([
            ['shippingCost' => ['value' => '9.00', 'currency' => 'GBP']],
            ['shippingCost' => ['value' => '1.00', 'currency' => 'USD']],
            ['type' => 'Collection'],
            ['shippingCost' => ['value' => '4.50', 'currency' => 'GBP']],
        ])));
        // No stored postage falls back to the combined estimate.
        self::assertNull(Pricing::listingPostage(['raw_attrs' => '{}']));
        self::assertSame(16.0, $pricing->shippingFor(['raw_attrs' => '{}', 'source_shipping' => '16']));
        self::assertSame(13.52, $pricing->calculate(285, 143.08, $paid)['shipping']);
    }

    public function testBuyerProtectionFeeIsBandedAndMaxBuyLeavesTheMinimumProfit(): void
    {
        self::assertSame(1.13, Pricing::buyerProtection(14.78));
        self::assertSame(6.42, Pricing::buyerProtection(143.08));
        self::assertSame(10.06, Pricing::buyerProtection(234));
        self::assertSame(16.7, Pricing::buyerProtection(500));
        self::assertSame(86.7, Pricing::buyerProtection(9000));

        $pricing = new Pricing($this->config(['target_profit' => '50']));
        $result = $pricing->calculate(285, 143.08);
        self::assertSame(81.45, $result['expected_profit']);
        self::assertSame(173.32, $result['max_safe_buy']);
        self::assertSame(50.0, $pricing->calculate(285, $result['max_safe_buy'])['expected_profit']);
    }

    public function testRoiSettingIsValidatedAsAPercentage(): void
    {
        self::assertTrue(Pricing::validSetting('20', Pricing::TARGET_ROI));
        self::assertTrue(Pricing::validSetting('1000', Pricing::TARGET_ROI));
        self::assertFalse(Pricing::validSetting('1000.01', Pricing::TARGET_ROI));
        self::assertTrue(Pricing::isSettingPath('ghostfrog_ebay/comps/target_roi_percent'));
    }

    private function config(array $values): ScopeConfigInterface
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn(string $path): ?string => $values[substr($path, strlen(Pricing::CONFIG_PREFIX))] ?? null
        );
        return $config;
    }

    #[DataProvider('eligibilityCases')]
    public function testComparableEligibility(array $changes, ?string $expectedGrade): void
    {
        $key = 'apple-macbook-air-a2337-133in-m1-8gb-256gb';
        $listing = $changes + [
            'source' => 'ebay-apple',
            'model_key' => $key,
            'condition_grade' => 'B',
            'raw_attrs' => '{"Screen Size":"13.3 in"}',
            'title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD',
        ];
        $eligibility = new Eligibility(new Classifier(new AppleMac(), new AppleDevices()));

        self::assertSame($expectedGrade === null ? null : [$key, $expectedGrade], $eligibility->group($listing));
    }

    public function testFaultyGroupCoversEverySourceButNotGameTitles(): void
    {
        $eligibility = new Eligibility(new Classifier(new AppleMac(), new AppleDevices()));
        $faultyConsole = [
            'source' => 'ebay-consoles', 'model_key' => 'sony-playstation-4-base-500gb', 'condition_grade' => null,
            'raw_attrs' => '{}', 'title' => 'Sony PlayStation 4 500GB Console Faulty Spares Repair',
        ];
        self::assertSame(['sony-playstation-4-base-500gb', 'F'], $eligibility->group($faultyConsole));

        $mario = json_encode(['Game Name' => 'Mario Kart 8 Deluxe', 'Platform' => 'Nintendo Switch', 'Browse categoryId' => '139973']);
        self::assertSame(['game-switch-mario-kart-8-deluxe', 'F'], $eligibility->group([
            'model_key' => 'game-switch-mario-kart-8-deluxe', 'raw_attrs' => $mario,
            'title' => 'Mario Kart 8 Deluxe Nintendo Switch faulty not working',
        ] + $faultyConsole));

        // "Dead" in a game title is part of the name, so the game is in neither group, as before.
        $evilDead = json_encode(['Game Name' => 'Evil Dead: A Fistful of Boomstick', 'Platform' => 'Sony PlayStation 2',
            'Browse categoryId' => '139973']);
        self::assertNull($eligibility->group([
            'model_key' => 'game-ps2-evil-dead-a-fistful-of-boomstick', 'raw_attrs' => $evilDead,
            'title' => 'Evil Dead A Fistful of Boomstick PS2',
        ] + $faultyConsole));

        $bike = json_encode(['Manufacturer' => 'BMW', 'Model' => 'S 1000 RR', 'Engine Size' => '999', 'Year' => '2016']);
        self::assertSame(['motomine-bmw-s1000rr-1000cc', 'F'], $eligibility->group([
            'source' => 'motomine', 'model_key' => 'motomine-bmw-s1000rr-1000cc', 'raw_attrs' => $bike,
            'title' => 'Lot#69602 2016 BMW S1000RR -SPARES/REPAIR (NON-STRUCTURAL)',
        ] + $faultyConsole));
    }

    public static function eligibilityCases(): iterable
    {
        yield 'same clean grade' => [[], 'B'];
        yield 'legacy missing grade' => [['condition_grade' => null], 'B'];
        yield 'visual evidence downgrades the grade' => [['visual_grade' => 'C'], 'C'];
        yield 'explicit unknown condition' => [['condition_grade' => 'unknown'], null];
        yield 'conflicting storage' => [['raw_attrs' => '{"SSD Capacity":"2 TB"}'], null];
        // Faulty listings get their own grade so they never mix with working sales.
        yield 'repair status in the title' => [['title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD spares/repair'], 'F'];
        yield 'eBay condition for parts' => [['raw_attrs' => '{"Screen Size":"13.3 in","Browse condition":"For parts or not working"}'], 'F'];
        yield 'faulty ignores its condition checks' => [
            ['title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD faulty', 'visual_grade' => 'D', 'text_review' => 1], 'F',
        ];
        yield 'fault wording only in the description is neither working nor faulty' => [
            ['raw_attrs' => '{"Screen Size":"13.3 in","Trading Description":"Grade B: isolated dead pixels that do not affect use"}'], null,
        ];
        yield 'negated fault in the description is neither working nor faulty' => [
            ['raw_attrs' => '{"Screen Size":"13.3 in","Trading Description":"IMMACULATE NO CRACKED SCREEN"}'], null,
        ];
        yield 'faulty rejected by operator' => [
            ['title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD faulty', 'review_action' => 'down'], null,
        ];
        yield 'faulty accessory is still excluded' => [
            ['title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD faulty charger only'], null,
        ];
        yield 'faulty job lot is still excluded' => [
            ['title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD job lot spares'], null,
        ];
        yield 'locked device is still excluded' => [
            ['title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD activation locked for parts'], null,
        ];
        yield 'accessory evidence' => [['raw_attrs' => '{"Type":"charger only"}'], null];
        yield 'accessory advertised with specs' => [
            ['title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD USB-C cable'], null,
        ];
        yield 'unresolved review' => [['text_review' => 1], null];
        yield 'rejected by operator' => [['review_action' => 'down'], null];
        yield 'functional fault' => [['visual_grade' => 'D'], null];
    }
}
