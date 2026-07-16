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

namespace ElasticApmBundle\Exception;

/**
 * Exception dedicated to configuration errors.
 *
 * This is thrown for manually-detected configuration preconditions, so it extends
 * \InvalidArgumentException rather than \ErrorException (which models a real PHP
 * error and carries severity/file/line semantics that do not apply here).
 */
final class ConfigurationException extends \InvalidArgumentException {}
