<?php

declare(strict_types=1);

namespace api\controllers;

use api\models\RecordDto;
use api\models\RecordModel;
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
    /**
     * Model service used by every endpoint to read and mutate record data.
     */
    #[AttachService('RecordModel')]
    protected RecordModel $recordModel;

    /**
     * Return every record as a JSON list for the records index view.
     */
    #[Route('get', '/api/index', 'rest_index')]
    public function index(): string
    {
        // Fetch all records from the model and format them as the standard list JSON response.
        return $this->listResponse($this->recordModel->index());
    }

    /**
     * Return a single record by id, or a 404 response when no record exists.
     */
    #[Route('get', '/api/read/(\d+)', 'rest_read')]
    public function read(string $id): string
    {
        // Route parameters arrive as strings, so cast the id before querying the model.
        $record = $this->recordModel->read((int)$id);

        // The model returns null/false for missing rows, so only DTO instances are valid results.
        if (!$record instanceof \api\models\RecordDto) {
            return $this->notFoundResponse('Record not found');
        }

        // Encode the DTO directly and return it with a successful HTTP status.
        return $this->response(200, json_encode($record, $this->jsonFlags));
    }

    /**
     * Create a record from the request JSON after validating the DTO fields.
     */
    #[Route('post', '/api/create', 'rest_create')]
    public function create(): string
    {
        // Build a DTO from the parsed request body so validation and persistence use one shape.
        $record = new RecordDto($this->input->request());

        // Stop before touching the database when required fields or formats are invalid.
        if (!$record->isValid()) {
            return $this->errorsResponse($record->allErrors());
        }

        // database failures throw (see RecordModel), so a returned id is real
        // Store the new id on the response payload for the client.
        $this->data->id = $this->recordModel->create($record);

        // Return 201 Created with the response payload prepared above.
        return $this->response(201);
    }

    /**
     * Update an existing record by id after checking existence and validation.
     */
    #[Route('put', '/api/update/(\d+)', 'rest_update')]
    public function update(string $id): string
    {
        // Convert the route id to an integer once so the rest of the method uses the DB id type.
        $id = (int)$id;

        // Return 404 before validation when the target row does not exist.
        if (!$this->recordModel->exists($id)) {
            return $this->notFoundResponse('Record not found');
        }

        // Merge the route id with the request data so the DTO contains the record identity.
        $record = new RecordDto(['id' => $id] + $this->input->request());

        // Report field-level validation errors instead of attempting an invalid update.
        if (!$record->isValid()) {
            return $this->errorsResponse($record->allErrors());
        }

        // Save the update result on the response payload for the client.
        $this->data->success = $this->recordModel->update($record);

        // Return 200 OK with the success payload prepared above.
        return $this->response(200);
    }

    /**
     * Delete an existing record by id and return an empty success response.
     */
    #[Route('delete', '/api/delete/(\d+)', 'rest_delete')]
    public function delete(string $id): string
    {
        // Convert the route id to the integer type expected by the model.
        $id = (int)$id;

        // Do not call delete when there is no row to delete.
        if (!$this->recordModel->exists($id)) {
            return $this->notFoundResponse('Record not found');
        }

        // a false here means the row vanished between the check and the
        // delete — from the client's view the record is simply not found
        if (!$this->recordModel->delete($id)) {
            return $this->notFoundResponse('Record not found');
        }

        // Successful deletes return 204 No Content, so the response has no JSON body.
        return $this->noContentResponse();
    }
}
