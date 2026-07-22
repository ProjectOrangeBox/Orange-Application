<?php

declare(strict_types=1);

namespace api\controllers;

use api\models\RecordDto;
use api\models\RecordModel;
use orange\dto\Dto;
use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;

/**
 * REST endpoints backing the Vue records client — the client side of this
 * contract is documented in the Vue app's stores/records.ts.
 *
 *   GET    /api/index        -> 200 [{id, name, phone, in_office, out_until}, ...]
 *   GET    /api/read/{id}    -> 200 record                | 404 {"msg": ...}
 *   POST   /api/create       -> 201 {"id": n}             | 422 {"errors": {...}}
 *   PUT    /api/update/{id}  -> 200 {"success": true}     | 404 | 422 {"errors": {...}}
 *   DELETE /api/delete/{id}  -> 204 (no body)             | 404 {"msg": ...}
 *
 * Request bodies are JSON (parsed by the framework's Input service).
 * Validation failures return 422 with messages keyed by input field name.
 */
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

        // database failures throw (see RecordModel), so a returned id is real
        $this->data->id = $this->recordModel->create($record);

        return $this->response(201);
    }

    #[Route('put', '/api/update/(\d+)', 'rest_update')]
    public function update(string $id): string
    {
        $id = (int)$id;

        if (!$this->recordModel->read($id) instanceof \api\models\RecordDto) {
            return $this->notFoundResponse();
        }

        $record = new RecordDto(['id' => $id] + $this->input->request());

        if (!$record->isValid()) {
            return $this->validationErrorResponse($record);
        }

        $this->data->success = $this->recordModel->update($record);

        return $this->response(200);
    }

    #[Route('delete', '/api/delete/(\d+)', 'rest_delete')]
    public function delete(string $id): string
    {
        $id = (int)$id;

        if (!$this->recordModel->read($id) instanceof \api\models\RecordDto) {
            return $this->notFoundResponse();
        }

        // a false here means the row vanished between the check and the
        // delete — from the client's view the record is simply not found
        if (!$this->recordModel->delete($id)) {
            return $this->notFoundResponse();
        }

        // 204 No Content must not carry a body
        return $this->response(204, '');
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
