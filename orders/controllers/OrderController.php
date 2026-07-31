<?php

declare(strict_types=1);

namespace orders\controllers;

use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;
use orange\negotiate\Negotiate;
use orders\models\OrderDto;
use orders\models\OrderModel;

/**
 * REST endpoints for orders and the lines they own.
 *
 *   GET    /api/orders                -> 200 [{id, customer_id, ordered_on, notes, lines: [...]}, ...]
 *                                        or CSV, by Accept header
 *   GET    /api/orders/(\d+)          -> 200 order                  | 404 {"msg": ...}
 *   POST   /api/orders                -> 201 {"id": n}              | 422 {"errors": {...}}
 *   PUT    /api/orders/(\d+)          -> 200 {"success": true}      | 404 | 422
 *   DELETE /api/orders/(\d+)          -> 204 (no body)              | 404 {"msg": ...}
 *
 * What makes this different from the flat records API is the 422 body. An order
 * carries a list of lines, each validated in its own right, so "which line" is
 * part of the answer:
 *
 *   {"errors": {"lines": {"1": {"qty": ["Quantity must be greater than 0"]}}}}
 *
 * The DTO does not hand that over directly - see extractLineErrors().
 */
class OrderController extends JsonController
{
    #[AttachService('OrderModel')]
    protected OrderModel $orderModel;

    #[AttachService('negotiate')]
    protected Negotiate $negotiate;

    /**
     * Every order, as JSON or CSV depending on what the client asked for.
     *
     * One route serving two representations, rather than a separate /export
     * endpoint: the resource is the same, only the rendering differs, which is
     * what the Accept header is for. A browser link wanting the spreadsheet
     * sends Accept: text/csv; the Vue app sends nothing special and gets JSON.
     */
    #[Route('get', '/api/orders', 'orders_index')]
    public function index(): string
    {
        $orders = $this->orderModel->index();

        // json is first, so it is also what a client with no preference gets.
        if ($this->negotiate->media(['application/json', 'text/csv']) === 'text/csv') {
            return $this->csvResponse($orders);
        }

        return $this->listResponse($orders);
    }

    #[Route('get', '/api/orders/(\d+)', 'orders_read')]
    public function read(string $id): string
    {
        $order = $this->orderModel->read((int) $id);

        if (!$order instanceof OrderDto) {
            return $this->notFoundResponse('Order not found');
        }

        $this->data->merge($order->asArray());

        return $this->response(200);
    }

    #[Route('post', '/api/orders', 'orders_create')]
    public function create(): string
    {
        $order = new OrderDto((array) $this->input->request());

        if (($errors = $this->validate($order)) !== []) {
            return $this->errorsResponse($errors);
        }

        $this->data['id'] = $this->orderModel->create($order);

        return $this->response(201);
    }

    #[Route('put', '/api/orders/(\d+)', 'orders_update')]
    public function update(string $id): string
    {
        if (!$this->orderModel->exists((int) $id)) {
            return $this->notFoundResponse('Order not found');
        }

        // The id comes from the route, not the body - a payload claiming a
        // different id would otherwise update a row the URL never named.
        $order = new OrderDto(['id' => (int) $id] + (array) $this->input->request());

        if (($errors = $this->validate($order)) !== []) {
            return $this->errorsResponse($errors);
        }

        $this->orderModel->update($order);

        $this->data['success'] = true;

        return $this->response(200);
    }

    #[Route('delete', '/api/orders/(\d+)', 'orders_delete')]
    public function delete(string $id): string
    {
        if (!$this->orderModel->delete((int) $id)) {
            return $this->notFoundResponse('Order not found');
        }

        return $this->noContentResponse();
    }

    /**
     * Everything wrong with this order, keyed the way the client can use it.
     *
     * @return array<string, mixed>
     */
    protected function validate(OrderDto $order): array
    {
        $errors = $this->extractLineErrors($order);

        // A customer that does not exist is a validation failure about the
        // customer_id field, not a database error. Only worth asking once the
        // field itself is well-formed, and only when nothing else is wrong.
        if ($errors === [] && !$this->orderModel->customerExists($order->customer_id)) {
            $errors['customer_id'] = ['Customer does not exist'];
        }

        return $errors;
    }

    /**
     * Replace the parent's rolled-up line error with per-row detail.
     *
     * #[IsArray(LineItemDto::class)] reports child failures on the parent as a
     * single message - 'Lines has 1 or more errors' - and keeps the detail on
     * each child. That is the right default for a DTO, and useless to a form:
     * the client needs to know it was row 1's quantity, not that something,
     * somewhere, in a list of forty lines is wrong.
     *
     * The child keys mirror the input array's keys, so the index here is the
     * index the client sent and can render against.
     *
     * @return array<string, mixed>
     */
    protected function extractLineErrors(OrderDto $order): array
    {
        $errors = $order->errors();

        // isset() is false for a typed property that was never initialised,
        // which is the case when 'lines' was not an array at all - there are no
        // children to report on and the parent's own message is the whole story.
        if (!isset($errors['lines']) || !isset($order->lines)) {
            return $errors;
        }

        $rows = [];

        foreach ($order->lines as $index => $line) {
            if (!$line->isValid()) {
                $rows[$index] = $line->errors();
            }
        }

        // No invalid children means the rollup was about the list itself -
        // MinCount, MaxCount, or a non-object entry - so leave it alone.
        if ($rows !== []) {
            $errors['lines'] = $rows;
        }

        return $errors;
    }

    /**
     * One row per line, with the order's own fields repeated against each.
     *
     * A spreadsheet has no way to express nesting, so the parent is flattened
     * onto its children. An order with no lines still gets a row, or it would
     * vanish from the export entirely.
     *
     * @param OrderDto[] $orders
     */
    protected function csvResponse(array $orders): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return $this->response(500);
        }

        // Separator, enclosure and escape are passed positionally on every call
        // below. PHP 8.4 deprecates relying on the escape default and this app
        // promotes deprecations, so omitting it is a 500; '' disables escaping,
        // which is what a spreadsheet expects - RFC 4180 quotes, no backslashes.
        //
        // Passing the three by spread instead reads better and is a trap:
        // Rector's AddEscapeArgumentRector cannot see through the spread,
        // appends its own `escape:` named argument, and PHP fatals on the
        // duplicate. Since sweep.sh runs rector in fix mode, that rewrite lands
        // silently on commit.
        fputcsv($handle, ['order_id', 'customer_id', 'ordered_on', 'notes', 'sku', 'description', 'qty', 'unit_price', 'line_total'], ',', '"', '');

        foreach ($orders as $order) {
            $head = [$order->id, $order->customer_id, $order->ordered_on, $order->notes];

            if ($order->lines === []) {
                fputcsv($handle, [...$head, '', '', '', '', ''], ',', '"', '');

                continue;
            }

            foreach ($order->lines as $line) {
                fputcsv($handle, [...$head, $line->sku, $line->description, $line->qty, $line->unit_price, $line->line_total], ',', '"', '');
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $this->output->contentType('text/csv');
        $this->output->header('Content-Disposition: attachment; filename="orders.csv"');

        return $this->response(200, $csv);
    }
}
