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

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the W3C distributed tracing headers of the producing transaction to the worker that consumes the message.
 */
final readonly class ApmTracingStamp implements StampInterface
{
    /**
     * @param array<string, string> $headers header name => value, as injected by the APM agent
     */
    public function __construct(
        private array $headers,
    ) {}

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $headerName => $value) {
            if (0 === \strcasecmp($headerName, $name)) {
                return $value;
            }
        }

        return null;
    }
}
