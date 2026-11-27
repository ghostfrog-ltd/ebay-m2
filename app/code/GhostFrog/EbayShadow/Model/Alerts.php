<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model;

use GhostFrog\Ebay\Api\SlackInterface;

/** Shadow messages through core's Slack webhook. Every message says it is simulated. */
class Alerts
{
    public function __construct(private readonly SlackInterface $slack)
    {
    }

    public function buy(string $pot, array $buy): void
    {
        if (!$this->slack->available()) {
            return;
        }
        $this->slack->sendText(implode("\n", [
            sprintf('[SHADOW, simulated, no real bid] %s placed a max bid', $pot),
            (string)$buy['title'],
            sprintf('Max bid £%.2f, current £%.2f, ends %s UTC', $buy['max_bid'], $buy['price_current'], $buy['end_time']),
            sprintf('Predicted resale £%.2f, cautious profit £%.2f, ROI %.1f%%, score %.5f',
                $buy['predicted_resale'], $buy['cautious_profit'], $buy['roi_percent'], $buy['score']),
        ]));
    }

    public function summary(string $text): bool
    {
        return $this->slack->available() && $this->slack->sendText("[SHADOW, simulated]\n" . $text);
    }
}
