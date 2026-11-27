<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Api;

/** Plain-text messages through core's configured Slack webhook. */
interface SlackInterface
{
    /** Whether core's Slack notifications are switched on and a webhook is configured. */
    public function available(): bool;

    /** @return bool True when Slack accepted the message. */
    public function sendText(string $text): bool;
}
