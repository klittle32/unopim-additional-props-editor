<?php

declare(strict_types=1);

namespace UnopimAdditionalPropsEditor\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnopimAdditionalPropsEditor\AdditionalData;
use UnopimAdditionalPropsEditor\AdditionalDataStore;

final class AdditionalDataController extends Controller
{
    public function show(int $id, AdditionalDataStore $store): JsonResponse
    {
        $this->authorizeProductEdit();

        return $this->respond($store->read($id));
    }

    public function update(Request $request, int $id, AdditionalDataStore $store): JsonResponse
    {
        $this->authorizeProductEdit();
        abort_unless($request->isJson(), 415, 'Send additional data as application/json.');

        try {
            // Read raw JSON: Laravel's global TrimStrings/ConvertEmptyStringsToNull
            // middleware must not change these deliberately string-valued fields.
            $input = AdditionalData::parseRequest($request->getContent());

            return $this->respond($store->update($id, $input));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['additional' => [$exception->getMessage()]]);
        }
    }

    private function authorizeProductEdit(): void
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403,
            'You do not have permission to edit products.');
    }

    private function respond(AdditionalData $data): JsonResponse
    {
        return response()->json($data->snapshot())->header('Cache-Control', 'no-store, private');
    }
}
