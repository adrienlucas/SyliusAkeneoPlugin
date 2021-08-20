<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Task\Family;

use Doctrine\ORM\EntityManagerInterface;
use Synolia\SyliusAkeneoPlugin\Payload\Family\FamilyPayload;
use Synolia\SyliusAkeneoPlugin\Payload\PipelinePayloadInterface;
use Synolia\SyliusAkeneoPlugin\Task\AkeneoTaskInterface;

class TearDownFamilyTask implements AkeneoTaskInterface
{
    /** @var \Doctrine\ORM\EntityManagerInterface */
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function __invoke(PipelinePayloadInterface $payload): PipelinePayloadInterface
    {
        $this->entityManager->getConnection()->connect();
        $exists = $this->entityManager->getConnection()->getSchemaManager()->tablesExist([FamilyPayload::TEMP_AKENEO_TABLE_NAME]);

        if ($exists) {
            $this->entityManager->getConnection()->getSchemaManager()->dropTable(FamilyPayload::TEMP_AKENEO_TABLE_NAME);
        }

        return $payload;
    }
}
