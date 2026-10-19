<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration\Fixtures;

use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class TestController
{
    public function hello(string $name): Response
    {
        return new Response('Hello '.$name);
    }

    public function fail(): Response
    {
        throw new \RuntimeException('Controller failed', 0, new \LogicException('Root cause'));
    }

    public function notFound(): Response
    {
        throw new NotFoundHttpException('No such thing');
    }

    public function unavailable(): Response
    {
        throw new ServiceUnavailableHttpException(null, 'Down for maintenance');
    }

    public function suspicious(): Response
    {
        throw new SuspiciousOperationException('Untrusted Host');
    }

    public function ignored(): Response
    {
        throw new IgnoredException('Expected and ignored');
    }

    public function ignoredSubclass(): Response
    {
        throw new IgnoredSubclassException('Subclass of an ignored exception');
    }
}
