<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;
use stdClass;

final class JsonRequest
{
    private const MAX_BYTES = 65536;

    public static function read(): array
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            throw new ApiException(415, 'Content-Type must be application/json');
        }

        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BYTES + 1);
        if ($raw === false) {
            throw new ApiException(400, 'Unable to read request body');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new ApiException(413, 'JSON request body is too large');
        }

        return self::decode($raw);
    }

    public static function decode(string $raw): array
    {
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ApiException(400, 'Request body must be valid JSON');
        }
        if (!$decoded instanceof stdClass) {
            throw new ApiException(422, 'Request body must be a JSON object');
        }

        $input = (array) $decoded;

        // json_decode converts decimal JSON numbers to binary floats. Recover the
        // exact top-level amount token so 1.0000000000000001 cannot become 1.00.
        // Tokenizing only after JSON validation keeps this a lexical pass, not a
        // second JSON parser. Quoted text and nested objects cannot spoof a key.
        preg_match_all(
            '~"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|[{}\[\]:,]|true|false|null~s',
            $raw,
            $matches,
        );
        $tokens = $matches[0];
        $depth = 0;
        $keys = [];
        foreach ($tokens as $index => $token) {
            if ($token === '{' || $token === '[') {
                $depth++;
                continue;
            }
            if ($token === '}' || $token === ']') {
                $depth--;
                continue;
            }
            if ($depth !== 1 || $token[0] !== '"' || ($tokens[$index + 1] ?? null) !== ':') {
                continue;
            }

            $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
            if (isset($keys[$key])) {
                throw new ApiException(422, 'Duplicate JSON object fields are not allowed');
            }
            $keys[$key] = true;
            $valueToken = $tokens[$index + 2];
            if ($key === 'amount' && ($valueToken[0] === '-' || ctype_digit($valueToken[0]))) {
                $input['amount'] = $valueToken;
            }
        }

        return $input;
    }
}
