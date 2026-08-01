<?php

declare(strict_types=1);

namespace application\api\models;

use orange\framework\base\Singleton;
use orange\model\Sql;
use PDO;

class CalendarEventModel extends Singleton
{
    /** @var array<string, mixed> */
    protected array $schema;
    protected Sql $sql;
    /** @var string[] */
    protected array $columns;
    protected string $tablename = 'calendar_events';
    protected string $primaryColumn = 'id';

    public function __construct(PDO $pdo)
    {
        $this->schema = CalendarEventDto::schema();
        $this->columns = $this->schema['columns'];

        $this->sql = new Sql([
            'tablename' => $this->tablename,
            'primaryColumn' => $this->primaryColumn,
            'throwException' => true,
        ], $pdo);
    }

    /**
     * @return CalendarEventDto[]
     */
    public function month(string $month): array
    {
        $start = $month . '-01';
        $next = strtotime($start . ' +1 month');

        // strtotime() answers false for anything it cannot parse, and
        // date('Y-m-d', false) is the epoch rather than an error - a malformed
        // month would quietly return January 1970's events instead of none.
        // CalendarController validates the format, but that is its business,
        // not something this method should assume was done.
        if ($next === false) {
            return [];
        }

        $end = date('Y-m-d', $next);
        $events = [];

        $query = $this->sql
            ->select($this->columns)
            ->whereRaw('event_date >= :start AND event_date < :end', [
                'start' => $start,
                'end' => $end,
            ])
            ->orderBy('event_date')
            ->orderBy($this->primaryColumn);

        // Sql::$pdoStatement is a typed, non-nullable property, so it is always
        // an object here - the truthiness check this used to carry could never
        // be false.
        $statement = $query->execute()->pdoStatement;

        while ($row = $statement->fetch()) {
            $events[] = $this->hydrate($row);
        }

        return $events;
    }

    public function create(CalendarEventDto $event): int
    {
        return $this->sql->insert()->set($event->asColumns(withoutPrimary: true))->execute()->lastInsertId();
    }

    public function read(int $id): ?CalendarEventDto
    {
        $row = $this->sql->select($this->columns)->wherePrimary($id)->limit(1)->execute()->row();

        return $row === false ? null : $this->hydrate($row);
    }

    public function exists(int $id): bool
    {
        return $this->sql->select($this->primaryColumn)->wherePrimary($id)->limit(1)->execute()->column() !== false;
    }

    public function update(CalendarEventDto $event): bool
    {
        $this->sql->update()->set($event->asColumns(withoutPrimary: true))->wherePrimary($event->primaryValue())->execute();

        return true;
    }

    public function delete(int $id): bool
    {
        return $this->sql->delete()->wherePrimary($id)->execute()->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function hydrate(array $row): CalendarEventDto
    {
        $event = new CalendarEventDto($row, fromDatabase: true);

        if (!$event->isValid()) {
            logMsg('WARNING', __METHOD__ . ' database row failed dto validation', [
                $this->primaryColumn => $row[$this->primaryColumn] ?? null,
                'errors' => $event->errors(),
            ]);
        }

        return $event;
    }
}
