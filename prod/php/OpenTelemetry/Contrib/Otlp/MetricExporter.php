<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Otlp;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceResponse;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use Throwable;

/**
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/sdk_exporters/stdout.md#opentelemetry-metrics-exporter---standard-output
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/protocol/file-exporter.md#json-file-serialization
 */
final class MetricExporter implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface
{
    use LogsMessagesTrait;

    /**
     * @param TransportInterface<ContentTypes::*> $transport
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string|Temporality|AggregationTemporalitySelectorInterface|null $temporality = null,
    ) {
    }

    #[\Override]
    public function temporality(MetricMetadataInterface $metric): Temporality|string|null
    {
        if ($this->temporality instanceof AggregationTemporalitySelectorInterface) {
            return $this->temporality->temporality($metric);
        }

        return $this->temporality ?? $metric->temporality();
    }

    /** @inheritDoc */
    #[\Override]
    public function export(iterable $batch): bool
    {
        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpFullyQualifiedNameUsageInspection
         */
        return $this->transport
            ->send(\OpenTelemetry\Distro\OtlpExporters\convert_metrics($batch))
            ->map(
                static function (mixed $payload): bool {
                    if ($payload === null) {
                        return true;
                    }

                    $serviceResponse = new ExportMetricsServiceResponse();

                    /** @noinspection DuplicatedCode */
                    $partialSuccess = $serviceResponse->getPartialSuccess();
                    if ($partialSuccess !== null && $partialSuccess->getRejectedDataPoints()) {
                        self::logError('Export partial success', [
                            'rejected_data_points' => $partialSuccess->getRejectedDataPoints(),
                            'error_message'        => $partialSuccess->getErrorMessage(),
                        ]);

                        return false;
                    }
                    if ($partialSuccess !== null && $partialSuccess->getErrorMessage()) {
                        self::logWarning('Export success with warnings/suggestions', ['error_message' => $partialSuccess->getErrorMessage()]);
                    }

                    return true;
                }
            )->catch(
                static function (Throwable $throwable): bool {
                    self::logError('Export failure', ['exception' => $throwable]);

                    return false;
                }
            )->await();
    }

    #[\Override]
    public function shutdown(): bool
    {
        return $this->transport->shutdown();
    }

    #[\Override]
    public function forceFlush(): bool
    {
        return $this->transport->forceFlush();
    }
}
