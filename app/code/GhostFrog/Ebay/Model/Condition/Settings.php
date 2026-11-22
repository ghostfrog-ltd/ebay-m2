<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Condition;

use Magento\Framework\App\Config\ScopeConfigInterface;
use RuntimeException;

final class Settings
{
    private const CONFIG = 'ghostfrog_ebay/condition/';
    private const MODEL = 'qwen3.6:35b-a3b-coding-nvfp4';

    public function __construct(private readonly ScopeConfigInterface $config)
    {
    }

    public function enabled(Stage $stage): bool
    {
        return $this->config->isSetFlag(self::CONFIG . $stage->value . '_enabled');
    }

    public function next(?Stage $after = null): ?Stage
    {
        if ($after === null && $this->enabled(Stage::Text)) {
            return Stage::Text;
        }
        if ($after !== Stage::Visual && $this->enabled(Stage::Visual)) {
            return Stage::Visual;
        }
        return null;
    }

    public function model(Stage $stage): string
    {
        return trim((string)$this->config->getValue(self::CONFIG . $stage->value . '_model')) ?: self::MODEL;
    }

    public function endpoint(): string
    {
        $host = getenv('IS_DDEV_PROJECT') === 'true' ? 'host.docker.internal' : '127.0.0.1';
        $base = rtrim(trim((string)$this->config->getValue(self::CONFIG . 'ollama_url')), '/')
            ?: 'http://' . $host . ':11434';
        $url = parse_url($base);
        if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || !in_array($url['host'] ?? '', ['127.0.0.1', '[::1]', 'localhost', 'host.docker.internal'], true)
            || !empty($url['path']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
        ) {
            throw new RuntimeException('Set Ollama to a local HTTP or HTTPS base URL.');
        }
        return $base . '/api/chat';
    }
}
