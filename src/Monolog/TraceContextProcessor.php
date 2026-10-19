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

namespace ElasticApmBundle\Monolog;

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds the current APM trace, transaction and span ids to every log record so a log line can be correlated
 * with its trace in Kibana. Requires Monolog 3.
 */
final readonly class TraceContextProcessor implements ProcessorInterface
{
    public const string DEFAULT_TRACE_ID_FIELD = 'trace.id';

    public const string DEFAULT_TRANSACTION_ID_FIELD = 'transaction.id';

    public const string DEFAULT_SPAN_ID_FIELD = 'span.id';

    public function __construct(
        private ElasticApmInteractorInterface $interactor,
        private string $traceIdField = self::DEFAULT_TRACE_ID_FIELD,
        private string $transactionIdField = self::DEFAULT_TRANSACTION_ID_FIELD,
        private string $spanIdField = self::DEFAULT_SPAN_ID_FIELD,
    ) {}

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        // Logging must never break because of APM, so any agent failure leaves the record untouched.
        try {
            $transaction = $this->interactor->getCurrentTransaction();

            // The agent returns a no-op transaction (with all-zero ids) when nothing is being recorded.
            if (null === $transaction || $transaction->isNoop()) {
                return $record;
            }

            $extra = $record->extra;
            $extra[$this->traceIdField] = $transaction->getTraceId();
            $extra[$this->transactionIdField] = $transaction->getId();

            $span = $transaction->getCurrentSpan();

            if (!$span->isNoop()) {
                $extra[$this->spanIdField] = $span->getId();
            }

            return $record->with(extra: $extra);
        } catch (\Throwable) {
            return $record;
        }
    }
}
