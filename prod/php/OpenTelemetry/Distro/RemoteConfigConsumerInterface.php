<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro;

/**
 * Interface for consumers of OpAMP remote configuration.
 *
 * Multiple consumers can be registered via {@see PhpPartFacade::registerRemoteConfigConsumer()}.
 * Each consumer receives the full file map from the native extension and decides
 * which files/keys to handle.
 *
 * @api
 */
interface RemoteConfigConsumerInterface
{
    /**
     * Applies remote configuration received via OpAMP.
     *
     * Called by {@see RemoteConfigHandler::fetchAndApply()} with the full file map
     * from the native extension. The consumer decides which files/keys to handle.
     *
     * @param array<string, string> $fileNameToContent Map of filename => raw content
     */
    public function applyRemoteConfig(array $fileNameToContent): void;
}
