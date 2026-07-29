<?php

declare(strict_types=1);

namespace api\controllers;

use api\models\CalendarEventDto;
use api\models\CalendarEventModel;
use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;

class CalendarController extends JsonController
{
    #[AttachService('CalendarEventModel')]
    protected CalendarEventModel $calendarEventModel;

    #[Route('get', '/api/calendar/(\d{4}-\d{2})', 'calendar_month')]
    public function month(string $month): string
    {
        if (!$this->validMonth($month)) {
            return $this->errorsResponse(['month' => ['Month must use YYYY-MM format']]);
        }

        return $this->listResponse($this->calendarEventModel->month($month));
    }

    #[Route('get', '/api/calendar/read/(\d+)', 'calendar_read')]
    public function read(string $id): string
    {
        $event = $this->calendarEventModel->read((int)$id);

        if (!$event instanceof CalendarEventDto) {
            return $this->notFoundResponse('Event not found');
        }

        return $this->response(200, json_encode($event, $this->jsonFlags));
    }

    #[Route('post', '/api/calendar/create', 'calendar_create')]
    public function create(): string
    {
        $event = new CalendarEventDto($this->input->request());

        if (!$event->isValid()) {
            return $this->errorsResponse($event->allErrors());
        }

        return $this->response(201, json_encode([
            'id' => $this->calendarEventModel->create($event),
        ], $this->jsonFlags));
    }

    #[Route('put', '/api/calendar/update/(\d+)', 'calendar_update')]
    public function update(string $id): string
    {
        $id = (int)$id;

        if (!$this->calendarEventModel->exists($id)) {
            return $this->notFoundResponse('Event not found');
        }

        $event = new CalendarEventDto(['id' => $id] + $this->input->request());

        if (!$event->isValid()) {
            return $this->errorsResponse($event->allErrors());
        }

        return $this->response(200, json_encode([
            'success' => $this->calendarEventModel->update($event),
        ], $this->jsonFlags));
    }

    #[Route('delete', '/api/calendar/delete/(\d+)', 'calendar_delete')]
    public function delete(string $id): string
    {
        $id = (int)$id;

        if (!$this->calendarEventModel->exists($id)) {
            return $this->notFoundResponse('Event not found');
        }

        if (!$this->calendarEventModel->delete($id)) {
            return $this->notFoundResponse('Event not found');
        }

        return $this->noContentResponse();
    }

    protected function validMonth(string $month): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m') === $month;
    }
}
