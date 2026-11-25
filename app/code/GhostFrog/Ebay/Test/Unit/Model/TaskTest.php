<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model;

use GhostFrog\Ebay\Model\Task;
use GhostFrog\Ebay\Model\Task\Category;
use GhostFrog\Ebay\Model\Task\Status;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskTest extends TestCase
{
    public function testNewTaskDefaultsToAnOpenTaskWithATrimmedTitle(): void
    {
        $task = $this->task(['title' => '  Record project goals  ']);
        $task->beforeSave();

        self::assertSame('Record project goals', $task->getData('title'));
        self::assertSame(Status::OPEN, $task->getData('status'));
        self::assertSame(Category::TASK, $task->getData('category'));
        self::assertNull($task->getData('completed_at'));
    }

    #[DataProvider('invalidTasks')]
    public function testInvalidTasksAreRejected(array $data): void
    {
        $this->expectException(LocalizedException::class);
        $this->task($data)->beforeSave();
    }

    public static function invalidTasks(): array
    {
        return [
            'blank title' => [['title' => '   ']],
            'long title' => [['title' => str_repeat('a', 256)]],
            'unknown status' => [['title' => 'x', 'status' => 'done']],
            'unknown category' => [['title' => 'x', 'category' => 'epic']],
        ];
    }

    #[DataProvider('closedStatuses')]
    public function testClosingATaskRecordsWhenItClosed(string $status): void
    {
        $task = $this->task(['title' => 'x', 'status' => Status::OPEN], ['status' => $status]);
        $task->beforeSave();

        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $task->getData('completed_at'));
    }

    public static function closedStatuses(): array
    {
        return [[Status::COMPLETE], [Status::WONT_DO]];
    }

    public function testEditingAClosedTaskKeepsItsClosedDate(): void
    {
        $task = $this->task(
            ['title' => 'x', 'status' => Status::COMPLETE, 'completed_at' => '2026-11-01 10:00:00'],
            ['title' => 'Renamed']
        );
        $task->beforeSave();

        self::assertSame('2026-11-01 10:00:00', $task->getData('completed_at'));
    }

    public function testReopeningATaskClearsItsClosedDate(): void
    {
        $task = $this->task(
            ['title' => 'x', 'status' => Status::COMPLETE, 'completed_at' => '2026-11-01 10:00:00'],
            ['status' => Status::IN_PROGRESS]
        );
        $task->beforeSave();

        self::assertNull($task->getData('completed_at'));
    }

    /** Builds a task as if loaded with $stored and then edited with $changes. */
    private function task(array $stored, array $changes = []): Task
    {
        $task = (new ObjectManager($this))->getObject(Task::class);
        $task->setData($stored);
        if ($changes !== []) {
            $task->setOrigData();
            $task->addData($changes);
        }
        return $task;
    }
}
