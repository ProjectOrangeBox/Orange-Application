<?php

declare(strict_types=1);

namespace api\controllers;

use api\models\RecordDto;
use api\models\RecordModel;
use orange\dto\Dto;
use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;

class RestController extends JsonController
{
    #[AttachService('RecordModel')]
    protected RecordModel $recordModel;

    #[Route('get', '/api/index', 'rest_index')]
    public function index(): string
    {
        return $this->listResponse($this->recordModel->index());
    }

    #[Route('get', '/api/read/(\d+)', 'rest_read')]
    public function read(string $id): string
    {
        $record = $this->recordModel->read((int)$id);

        if (!$record instanceof \api\models\RecordDto) {
            return $this->notFoundResponse();
        }

        return $this->response(200, json_encode($record, $this->jsonFlags));
    }

    #[Route('post', '/api/create', 'rest_create')]
    public function create(): string
    {
        $record = new RecordDto($this->input->request());

        if (!$record->isValid()) {
            return $this->validationErrorResponse($record);
        }

        $id = $this->recordModel->create($record);

        if ($id === 0) {
            return $this->response(422);
        }

        $this->data->id = $id;

        return $this->response(201);
    }

    #[Route('put', '/api/update/(\d+)', 'rest_update')]
    public function update(string $id): string
    {
        if (!$this->recordModel->read((int)$id) instanceof \api\models\RecordDto) {
            return $this->notFoundResponse();
        }

        $record = new RecordDto(['id' => (int)$id] + $this->input->request());

        if (!$record->isValid()) {
            return $this->validationErrorResponse($record);
        }

        $this->data->success = $this->recordModel->update($record);

        return $this->response(202);
    }

    #[Route('delete', '/api/delete/(\d+)', 'rest_delete')]
    public function delete(string $id): string
    {
        if (!$this->recordModel->read((int)$id) instanceof \api\models\RecordDto) {
            return $this->notFoundResponse();
        }

        if (!$this->recordModel->delete((int)$id)) {
            return $this->response(422);
        }

        return $this->response(202);
    }

    /**
     * Converts DTO validation errors into a JSON payload the Vue client can
     * display, keyed by input field name:
     *
     *   {"errors": {"in_office": ["in_office must contain a boolean"]}}
     *
     * Sent with 422 Unprocessable Entity — the REST convention for a
     * well-formed request that fails semantic validation.
     */
    protected function validationErrorResponse(Dto $dto): string
    {
        $this->data->errors = $dto->errors();

        return $this->response(422);
    }

    /**
     * 404 with a display message the Vue client shows in its error panel:
     *
     *   {"msg": "Record not found"}
     */
    protected function notFoundResponse(string $msg = 'Record not found'): string
    {
        $this->data->msg = $msg;

        return $this->response(404);
    }
}
