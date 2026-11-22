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
            'max_safe_buy' => 214.01,
            'expected_profit' => 76.01,
            'expected_roi_percent' => 40.43,
            'meets_target' => true,
        ], $pricing->calculate(322.995, 188));
    }

    public function testPricingUsesAdminSettingsIncludingZero(): void
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(static fn(string $path): string => match ($path) {
            'ghostfrog_ebay/comps/target_profit' => '100.00',
            'ghostfrog_ebay/comps/selling_fee_percent' => '0',
            'ghostfrog_ebay/comps/shipping' => '20.00',
        });

        self::assertSame([
            'max_safe_buy' => 275.0,
            'expected_profit' => 75.0,
            'expected_roi_percent' => 25.0,
            'meets_target' => false,
        ], (new Pricing($config))->calculate(400, 300));
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

    public static function eligibilityCases(): iterable
    {
        yield 'same clean grade' => [[], 'B'];
        yield 'legacy missing grade' => [['condition_grade' => null], 'B'];
        yield 'visual evidence downgrades the grade' => [['visual_grade' => 'C'], 'C'];
        yield 'explicit unknown condition' => [['condition_grade' => 'unknown'], null];
        yield 'conflicting storage' => [['raw_attrs' => '{"SSD Capacity":"2 TB"}'], null];
        yield 'repair status in the title' => [['title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD spares/repair'], null];
        yield 'accessory evidence' => [['raw_attrs' => '{"Type":"charger only"}'], null];
        yield 'accessory advertised with specs' => [
            ['title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD USB-C cable'], null,
        ];
        yield 'unresolved review' => [['text_review' => 1], null];
        yield 'rejected by operator' => [['review_action' => 'down'], null];
        yield 'functional fault' => [['visual_grade' => 'D'], null];
    }
}
