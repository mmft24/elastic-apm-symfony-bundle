<?php

declare(strict_types=1);

/*
 * This file is part of the Elastic APM Symfony Bundle.
 *
 * (c) mmft24
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ElasticApmBundle\Messenger;

use Elastic\Apm\DistributedTracingData;

/**
 * Parses a W3C `traceparent` header (`<version>-<trace-id>-<parent-id>-<flags>`) the same way the Elastic APM agent
 * does for incoming HTTP requests. Anything malformed yields null so a bad header can never break message handling.
 *
 * @see https://www.w3.org/TR/trace-context/#traceparent-header
 *
 * @internal
 */
final class TraceParentParser
{
    private const string SUPPORTED_VERSION = '00';

    private const string INVALID_TRACE_ID = '00000000000000000000000000000000';

    private const string INVALID_PARENT_ID = '0000000000000000';

    private const int SAMPLED_FLAG = 0b00000001;

    public static function parse(?string $traceParent): ?DistributedTracingData
    {
        if (null === $traceParent || !\class_exists(DistributedTracingData::class)) {
            return null;
        }

        // A future version may append more dash-separated fields, which must be tolerated.
        $parts = \explode('-', \trim($traceParent), 5);

        if (\count($parts) < 4) {
            return null;
        }

        [$version, $traceId, $parentId, $flags] = $parts;

        if (!self::isHex($version, 2) || 0 === \strcasecmp($version, 'ff')) {
            return null;
        }

        if (self::SUPPORTED_VERSION === $version && 4 !== \count($parts)) {
            return null;
        }

        $traceId = \strtolower($traceId);
        $parentId = \strtolower($parentId);

        if (!self::isHex($traceId, 32) || self::INVALID_TRACE_ID === $traceId) {
            return null;
        }

        if (!self::isHex($parentId, 16) || self::INVALID_PARENT_ID === $parentId) {
            return null;
        }

        if (!self::isHex($flags, 2)) {
            return null;
        }

        $data = new DistributedTracingData();
        $data->traceId = $traceId;
        $data->parentId = $parentId;
        $data->isSampled = (\hexdec($flags) & self::SAMPLED_FLAG) === self::SAMPLED_FLAG;

        return $data;
    }

    private static function isHex(string $value, int $length): bool
    {
        return \strlen($value) === $length && \ctype_xdigit($value);
    }
}
