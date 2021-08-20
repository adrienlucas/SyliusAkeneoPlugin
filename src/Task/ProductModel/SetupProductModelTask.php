<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Task\ProductModel;

use Doctrine\ORM\EntityManagerInterface;
use Synolia\SyliusAkeneoPlugin\Payload\PipelinePayloadInterface;
use Synolia\SyliusAkeneoPlugin\Payload\ProductModel\ProductModelPayload;
use Synolia\SyliusAkeneoPlugin\Provider\AkeneoTaskProvider;
use Synolia\SyliusAkeneoPlugin\Task\AkeneoTaskInterface;

class SetupProductModelTask implements AkeneoTaskInterface
{
    /** @var \Doctrine\ORM\EntityManagerInterface */
    private $entityManager;

    /** @var \Synolia\SyliusAkeneoPlugin\Provider\AkeneoTaskProvider */
    private $taskProvider;

    public function __construct(EntityManagerInterface $entityManager, AkeneoTaskProvider $taskProvider)
    {
        $this->entityManager = $entityManager;
        $this->taskProvider = $taskProvider;
    }

    public function __invoke(PipelinePayloadInterface $payload): PipelinePayloadInterface
    {
        if ($payload->isContinue()) {
            $schemaManager = $this->entityManager->getConnection()->getSchemaManager();
            $tableExist = $schemaManager->tablesExist([ProductModelPayload::TEMP_AKENEO_TABLE_NAME]);

            if (true === $tableExist) {
                return $payload;
            }
        }

        $this->taskProvider->get(TearDownProductModelTask::class)->__invoke($payload);

        $query = \sprintf(
            'CREATE TABLE `%s` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `values` JSON NULL,
              PRIMARY KEY (`id`));',
            ProductModelPayload::TEMP_AKENEO_TABLE_NAME
        );
        $this->entityManager->getConnection()->executeStatement($query);

        return $payload;
    }
}
