<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Api;

/** The local Ollama endpoint core is configured to use. */
interface LocalLlmInterface
{
    /**
     * Full /api/chat URL of the configured local Ollama.
     *
     * @throws \RuntimeException When the configured base URL is not a local address.
     */
    public function chatEndpoint(): string;

    /** Model core uses for text condition checks. */
    public function textModel(): string;
}
