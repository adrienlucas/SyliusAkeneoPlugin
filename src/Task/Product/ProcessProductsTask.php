<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Task\Product;

use Akeneo\Pim\ApiClient\Pagination\Page;
use Akeneo\Pim\ApiClient\Pagination\PageInterface;
use BluePsyduck\SymfonyProcessManager\ProcessManagerInterface;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Synolia\SyliusAkeneoPlugin\Filter\ProductFilter;
use Synolia\SyliusAkeneoPlugin\Logger\Messages;
use Synolia\SyliusAkeneoPlugin\Payload\PipelinePayloadInterface;
use Synolia\SyliusAkeneoPlugin\Payload\Product\ProductPayload;
use Synolia\SyliusAkeneoPlugin\Provider\ConfigurationProvider;
use Synolia\SyliusAkeneoPlugin\Task\AbstractBatchTask;

final class ProcessProductsTask extends AbstractBatchTask
{
    /** @var \Synolia\SyliusAkeneoPlugin\Provider\ConfigurationProvider */
    private $configurationProvider;

    /** @var \Synolia\SyliusAkeneoPlugin\Filter\ProductFilter */
    private $productFilter;

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $akeneoLogger,
        RepositoryInterface $apiConfigurationRepository,
        ProcessManagerInterface $processManager,
        BatchProductsTask $task,
        ConfigurationProvider $configurationProvider,
        ProductFilter $productFilter,
        string $projectDir
    ) {
        parent::__construct($entityManager, $processManager, $task, $akeneoLogger, $apiConfigurationRepository, $projectDir);
        $this->configurationProvider = $configurationProvider;
        $this->productFilter = $productFilter;
    }

    /**
     * @param \Synolia\SyliusAkeneoPlugin\Payload\Product\ProductPayload $payload
     */
    public function __invoke(PipelinePayloadInterface $payload): PipelinePayloadInterface
    {
        $this->logger->debug(self::class);

        if ($payload->isContinue()) {
            $this->process($payload);

            return $payload;
        }

        $this->logger->notice(Messages::retrieveFromAPI($payload->getType()));

        $queryParameters = $this->productFilter->getProductFilters();
        $queryParameters['pagination_type'] = 'search_after';

        /** @var \Akeneo\Pim\ApiClient\Pagination\PageInterface|null $resources */
        $resources = $payload->getAkeneoPimClient()->getProductApi()->listPerPage(
            $this->configurationProvider->getConfiguration()->getPaginationSize(),
            true,
            $queryParameters
        );

        if (!$resources instanceof Page) {
            return $payload;
        }

        $count = 0;
        $ids = [];

        $this->handleProducts($payload, $resources, $count, $ids);

        if ($count > 0 && $payload->isBatchingAllowed() && $payload->getProcessAsSoonAsPossible() && $payload->allowParallel()) {
            $this->logger->notice('Batching', ['from_id' => $ids[0], 'to_id' => $ids[\count($ids) - 1]]);
            $this->batch($payload, $ids);
            $this->processManager->waitForAllProcesses();
        }

        if ($count > 0 && !$payload->isBatchingAllowed()) {
            $payload->setIds($ids);
            $this->task->__invoke($payload);

            return $payload;
        }

        if ($count > 0 && !$payload->getProcessAsSoonAsPossible()) {
            $this->process($payload);
        }

        return $payload;
    }

    private function handleProducts(
        PipelinePayloadInterface $payload,
        PageInterface $page,
        int &$count = 0,
        array &$ids = []
    ): void {
        while (
            ($page instanceof Page && $page->hasNextPage()) ||
            ($page instanceof Page && !$page->hasPreviousPage()) ||
            $page instanceof Page
        ) {
            foreach ($page->getItems() as $item) {
                $sql = \sprintf(
                    'INSERT INTO `%s` (`values`, `is_simple`) VALUES (:values, :is_simple);',
                    ProductPayload::TEMP_AKENEO_TABLE_NAME,
                );

                $stmt = $this->entityManager->getConnection()->prepare($sql);
                $stmt->bindValue('values', \json_encode($item));
                $stmt->bindValue('is_simple', null === $item['parent'], ParameterType::BOOLEAN);
                $stmt->execute();
                ++$count;

                $ids[] = $this->entityManager->getConnection()->lastInsertId();

                if ($payload->getProcessAsSoonAsPossible() && $payload->allowParallel() && $count % $payload->getBatchSize() === 0) {
                    $this->logger->notice('Batching', ['from_id' => $ids[0], 'to_id' => $ids[\count($ids) - 1]]);
                    $this->batch($payload, $ids);
                    $ids = [];
                }
            }

            $page = $page->getNextPage();
        }
    }

    protected function createBatchPayload(PipelinePayloadInterface $payload): PipelinePayloadInterface
    {
        $commandContext = ($payload->hasCommandContext()) ? $payload->getCommandContext() : null;

        return new ProductPayload($payload->getAkeneoPimClient(), $commandContext);
    }
}
