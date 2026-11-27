<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Service;

use GhostFrog\Ebay\Api\LocalLlmInterface;
use GhostFrog\Ebay\Model\Condition\Settings;
use GhostFrog\Ebay\Model\Condition\Stage;

final class LocalLlm implements LocalLlmInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function chatEndpoint(): string
    {
        return $this->settings->endpoint();
    }

    public function textModel(): string
    {
        return $this->settings->model(Stage::Text);
    }
}
