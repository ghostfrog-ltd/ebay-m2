<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\StockUnit;

use Magento\Catalog\Model\ImageUploader;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Stock unit photos, kept under pub/media/ghostfrog_ledger/stock. */
class Photos
{
    public const BASE_PATH = 'ghostfrog_ledger/stock';

    public function __construct(
        private readonly ImageUploader $uploader,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Moves newly uploaded photos out of the temporary folder.
     *
     * @param array $posted The photos field as the form posts it.
     * @return list<string> File names to store.
     */
    public function keep(array $posted): array
    {
        $files = [];
        foreach ($posted as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $name = basename((string)($photo['file'] ?? $photo['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $files[] = !empty($photo['tmp_name']) ? basename($this->uploader->moveFileFromTmp($name, true)) : $name;
        }
        return array_values(array_unique($files));
    }

    /** The form's view of stored photos. */
    public function forForm(?string $json): array
    {
        $files = json_decode((string)$json, true);
        if (!is_array($files)) {
            return [];
        }
        $base = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . self::BASE_PATH . '/';
        return array_map(static fn(string $file) => [
            'name' => $file, 'file' => $file, 'url' => $base . rawurlencode($file), 'type' => 'image',
        ], array_values(array_filter($files, 'is_string')));
    }
}
