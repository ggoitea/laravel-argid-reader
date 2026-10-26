<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Parsing;

use Ggoitea\LaravelArgIdReader\Domain\DniData;
use Ggoitea\LaravelArgIdReader\Exceptions\UnsupportedPayload;

class BarcodePayloadParser
{
    public function __construct(
        private readonly Payload2012Parser $parser2012 = new Payload2012Parser,
        private readonly Payload2024Parser $parser2024 = new Payload2024Parser,
    ) {}

    public function parse(?string $payload): ?DniData
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        $parts = explode('@', $payload);
        $count = count($parts);

        if ($count === 9) {
            try {
                return $this->parser2012->parse($payload);
            } catch (UnsupportedPayload) {
                throw new UnsupportedPayload('unsupported payload');
            }
        }

        if ($count === 8) {
            try {
                return $this->parser2024->parse($payload);
            } catch (UnsupportedPayload) {
                throw new UnsupportedPayload('unsupported payload');
            }
        }

        if ($count > 9) {
            throw new UnsupportedPayload('unsupported payload');
        }

        throw new UnsupportedPayload('unsupported payload');
    }
}
