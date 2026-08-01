<?php

declare(strict_types=1);

use application\api\models\CalendarEventDto;
use application\api\models\CalendarEventModel;

final class CalendarEventModelTest extends unitTestHelper
{
    protected CalendarEventModel $model;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE calendar_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                event_date TEXT NOT NULL
            )
            SQL);

        $this->pdo->exec(<<<'SQL'
            INSERT INTO calendar_events (id, title, description, event_date) VALUES
                (5, 'Planning', 'Monthly planning', '2026-07-10'),
                (2, 'Review', 'Quarterly review', '2026-07-03'),
                (9, 'August kickoff', 'Next month', '2026-08-01')
            SQL);

        $this->model = CalendarEventModel::getInstance($this->pdo);
    }

    protected function dto(array $overrides = []): CalendarEventDto
    {
        return new CalendarEventDto($overrides + [
            'title' => 'New Event',
            'description' => 'Details',
            'date' => '2026-07-21',
        ]);
    }

    public function testMonthReturnsOnlyRequestedMonthOrderedByDate(): void
    {
        $events = $this->model->month('2026-07');

        $this->assertCount(2, $events);
        $this->assertContainsOnlyInstancesOf(CalendarEventDto::class, $events);
        $this->assertSame([2, 5], array_map(fn ($event) => $event->id, $events));
    }

    public function testCreateReadUpdateAndDelete(): void
    {
        $id = $this->model->create($this->dto());

        $event = $this->model->read($id);

        $this->assertInstanceOf(CalendarEventDto::class, $event);
        $this->assertSame('New Event', $event->title);
        $this->assertSame('2026-07-21', $event->date);

        $this->assertTrue($this->model->update($this->dto([
            'id' => $id,
            'title' => 'Updated Event',
            'date' => '2026-07-22',
        ])));

        $this->assertSame('Updated Event', $this->model->read($id)->title);
        $this->assertTrue($this->model->delete($id));
        $this->assertNull($this->model->read($id));
    }
}
