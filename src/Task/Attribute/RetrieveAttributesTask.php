<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Task\Attribute;

use Psr\Log\LoggerInterface;
use Synolia\SyliusAkeneoPlugin\Logger\Messages;
use Synolia\SyliusAkeneoPlugin\Payload\Attribute\AttributePayload;
use Synolia\SyliusAkeneoPlugin\Payload\PipelinePayloadInterface;
use Synolia\SyliusAkeneoPlugin\Provider\ConfigurationProvider;
use Synolia\SyliusAkeneoPlugin\Task\AkeneoTaskInterface;

final class RetrieveAttributesTask implements AkeneoTaskInterface
{
    /** @var LoggerInterface */
    private $logger;

    /** @var ConfigurationProvider */
    private $configurationProvider;

    public function __construct(LoggerInterface $akeneoLogger, ConfigurationProvider $configurationProvider)
    {
        $this->logger = $akeneoLogger;
        $this->configurationProvider = $configurationProvider;
    }

    /**
     * @param \Synolia\SyliusAkeneoPlugin\Payload\Attribute\AttributePayload $payload
     */
    public function __invoke(PipelinePayloadInterface $payload): PipelinePayloadInterface
    {
        $this->logger->debug(self::class);
        $this->logger->notice(Messages::retrieveFromAPI($payload->getType()));
        $resources = $payload->getAkeneoPimClient()->getAttributeApi()->all(
            $this->configurationProvider->getConfiguration()->getPaginationSize()
        );

        $noCodeCount = 0;
        foreach ($resources as $resource) {
            if (empty($resource['code'])) {
                ++$noCodeCount;
            }
        }

        $this->logger->info(Messages::totalToImport($payload->getType(), $resources->key()));
        if ($noCodeCount > 0) {
            $this->logger->warning(Messages::noCodeToImport($payload->getType(), $noCodeCount));
        }

        $payload = new AttributePayload($payload->getAkeneoPimClient());
        $payload->setResources($resources);

        return $payload;
    }
}
