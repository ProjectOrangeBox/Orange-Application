<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Events behind /api/calendar.
 *
 * The column is event_date while the Dto property is date - see the #[Column]
 * attribute on application/api/models/CalendarEventDto. The index is what makes
 * the month query a range scan rather than a table scan.
 */
final class CreateCalendarEvents extends AbstractMigration
{
    public function change(): void
    {
        $this->table('calendar_events', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('title', 'string', ['null' => false, 'limit' => 128])
            ->addColumn('description', 'text', ['null' => false])
            ->addColumn('event_date', 'date', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['event_date'], ['name' => 'idx_calendar_events_event_date'])
            ->create();
    }
}
