<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\TransactionNamingStrategy\Fixtures;

final class NonStaticController
{
    public function action(): void {}
}
