<?php

declare(strict_types=1);

/*
 * This file is part of Ekino New Relic bundle.
 *
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ElasticApmBundle\Interactor;

use ElasticApmBundle\Exception\ConfigurationException;

/**
 * This value object contains data and configuration that should be passed to the interactors.
 */
class Config
{
    private readonly string $memoryUsageLabelName;

    /**
     * @param array<string, string|int|float> $customLabels
     * @param array<string, string|int|float> $customContext
     * @param bool $shouldCollectMemoryUsage
     * @param string $memoryUsageLabelName
     * @param bool $shouldExplicitlyCollectCommandExceptions
     * @param bool $shouldUnwrapExceptions
     * @throws ConfigurationException
     */
    public function __construct(
        private array $customLabels,
        private array $customContext,
        private readonly bool $shouldCollectMemoryUsage,
        string $memoryUsageLabelName,
        private readonly bool $shouldExplicitlyCollectCommandExceptions,
        private readonly bool $shouldUnwrapExceptions,
    ) {
        if ('' === $memoryUsageLabelName) {
            throw new ConfigurationException('$memoryUsageLabelName cannot be blank');
        }
        $this->memoryUsageLabelName = $memoryUsageLabelName;
    }

    /**
     * @param array<string, string|int|float> $customLabels
     */
    public function setCustomLabels(array $customLabels): void
    {
        $this->customLabels = $customLabels;
    }

    public function addCustomLabels(string $name, string|int|float|bool $value): void
    {
        $this->customLabels[$name] = $value;
    }

    /**
     * @return float[]|int[]|string[]
     */
    public function getCustomLabels(): array
    {
        return $this->customLabels;
    }

    /**
     * @param array<string, string|int|float> $customContext
     */
    public function setCustomContext(array $customContext): void
    {
        $this->customContext = $customContext;
    }

    public function addCustomContext(string $name, string|int|float|bool $value): void
    {
        $this->customContext[$name] = $value;
    }

    /**
     * @return float[]|int[]|string[]
     */
    public function getCustomContext(): array
    {
        return $this->customContext;
    }

    public function shouldCollectMemoryUsage(): bool
    {
        return $this->shouldCollectMemoryUsage;
    }

    public function getMemoryUsageLabelName(): string
    {
        return $this->memoryUsageLabelName;
    }

    public function shouldExplicitlyCollectCommandExceptions(): bool
    {
        return $this->shouldExplicitlyCollectCommandExceptions;
    }

    public function shouldUnwrapExceptions(): bool
    {
        return $this->shouldUnwrapExceptions;
    }
}
