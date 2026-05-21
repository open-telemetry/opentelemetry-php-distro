<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * SPI ComponentProvider that exposes OverrideOTelSdkResourceAttributes as a resource detector
 * for file-based (declarative) configuration.
 *
 * Usage in YAML config:
 *   resource:
 *     detection/development:
 *       detectors:
 *         - distro: {}
 *
 * @implements ComponentProvider<ResourceDetectorInterface>
 *
 * @internal
 */
final class DistroDetectorComponentProvider implements ComponentProvider
{
    public static function registerSpi(): void
    {
        ServiceLoader::register(ComponentProvider::class, self::class);
    }

    /**
     * @param array{} $properties
     */
    #[\Override]
    public function createPlugin(array $properties, Context $context): ResourceDetectorInterface
    {
        return new OverrideOTelSdkResourceAttributes();
    }

    #[\Override]
    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        return $builder->arrayNode('distro');
    }
}
