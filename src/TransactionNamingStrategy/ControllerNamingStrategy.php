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

namespace ElasticApmBundle\TransactionNamingStrategy;

use Symfony\Component\HttpFoundation\Request;

/**
 * @author Magnus Nordlander
 * @author Bart van den Burg <bart@burgov.nl>
 */
final class ControllerNamingStrategy implements TransactionNamingStrategyInterface
{
    #[\Override]
    public function getTransactionName(Request $request): string
    {
        $controller = $request->attributes->get('_controller');
        if (empty($controller)) {
            return 'Unknown Symfony controller';
        }

        if ($controller instanceof \Closure) {
            return 'Closure controller';
        }

        if (\is_object($controller)) {
            if (\method_exists($controller, '__invoke')) {
                return 'Callback controller: '.$controller::class.'::__invoke()';
            }
        }

        if (\is_array($controller) && \is_callable($controller)) {
            // A callable array's first element may be an object (e.g. [$controller, 'action'])
            // or a class name; resolving it through a mixed-typed helper keeps both static
            // analysers happy, since they infer conflicting types for the array element.
            return 'Callback controller: '.$this->resolveClassName($controller[0]).'::'.$controller[1].'()';
        }

        if (\is_string($controller) && \is_callable($controller)) {
            return 'Callback controller: '.$controller.'()';
        }

        // A non-callable, non-object controller (e.g. an already-resolved class
        // name string) is returned as-is; anything else cannot satisfy the string
        // return type, so fall back to the unknown-controller label.
        return \is_string($controller) ? $controller : 'Unknown Symfony controller';
    }

    private function resolveClassName(mixed $classOrObject): string
    {
        if (\is_object($classOrObject)) {
            return $classOrObject::class;
        }

        return \is_string($classOrObject) ? $classOrObject : '';
    }
}
