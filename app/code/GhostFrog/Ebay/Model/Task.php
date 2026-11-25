<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model;

use GhostFrog\Ebay\Model\Task\Category;
use GhostFrog\Ebay\Model\Task\Status;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;

/** A GhostFrog project goal, feature, bug or other task recorded in Magento Admin. */
class Task extends AbstractModel
{
    protected $_eventPrefix = 'ghostfrog_ebay_task';

    protected function _construct(): void
    {
        $this->_init(ResourceModel\Task::class);
    }

    public function beforeSave()
    {
        $title = trim((string)$this->getData('title'));
        if ($title === '') {
            throw new LocalizedException(__('Enter a title.'));
        }
        if (mb_strlen($title) > 255) {
            throw new LocalizedException(__('Enter a title with 255 characters or fewer.'));
        }
        $this->setData('title', $title);

        $status = (string)($this->getData('status') ?: Status::OPEN);
        if (!isset(Status::LABELS[$status])) {
            throw new LocalizedException(__('Select a valid status.'));
        }
        $category = (string)($this->getData('category') ?: Category::TASK);
        if (!isset(Category::LABELS[$category])) {
            throw new LocalizedException(__('Select a valid category.'));
        }
        $this->setData('status', $status);
        $this->setData('category', $category);

        // Record when a task closes, and clear it if the task is reopened.
        $closed = in_array($status, Status::CLOSED, true);
        if (!$closed) {
            $this->setData('completed_at', null);
        } elseif (!$this->getData('completed_at')
            || !in_array((string)$this->getOrigData('status'), Status::CLOSED, true)
        ) {
            $this->setData('completed_at', gmdate('Y-m-d H:i:s'));
        }
        return parent::beforeSave();
    }
}
