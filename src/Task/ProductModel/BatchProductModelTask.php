<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Task\ProductModel;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Synolia\SyliusAkeneoPlugin\Entity\ProductConfiguration;
use Synolia\SyliusAkeneoPlugin\Entity\ProductGroup;
use Synolia\SyliusAkeneoPlugin\Event\Product\AfterProcessingProductEvent;
use Synolia\SyliusAkeneoPlugin\Event\Product\BeforeProcessingProductEvent;
use Synolia\SyliusAkeneoPlugin\Exceptions\NoAttributeResourcesException;
use Synolia\SyliusAkeneoPlugin\Logger\Messages;
use Synolia\SyliusAkeneoPlugin\Payload\PipelinePayloadInterface;
use Synolia\SyliusAkeneoPlugin\Payload\Product\ProductCategoriesPayload;
use Synolia\SyliusAkeneoPlugin\Payload\Product\ProductMediaPayload;
use Synolia\SyliusAkeneoPlugin\Payload\ProductModel\ProductModelPayload;
use Synolia\SyliusAkeneoPlugin\Processor\Product\AttributesProcessorInterface;
use Synolia\SyliusAkeneoPlugin\Processor\Product\CompleteRequirementProcessorInterface;
use Synolia\SyliusAkeneoPlugin\Processor\Product\MainTaxonProcessorInterface;
use Synolia\SyliusAkeneoPlugin\Provider\AkeneoTaskProvider;
use Synolia\SyliusAkeneoPlugin\Service\ProductChannelEnabler;
use Synolia\SyliusAkeneoPlugin\Task\AkeneoTaskInterface;
use Synolia\SyliusAkeneoPlugin\Task\BatchTaskInterface;
use Synolia\SyliusAkeneoPlugin\Task\Product\AddProductToCategoriesTask;
use Synolia\SyliusAkeneoPlugin\Task\Product\InsertProductImagesTask;

final class BatchProductModelTask implements AkeneoTaskInterface, BatchTaskInterface
{
    private const ONE_VARIATION_AXIS = 1;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var ProductFactoryInterface */
    private $productFactory;

    /** @var \Synolia\SyliusAkeneoPlugin\Repository\ProductGroupRepository */
    private $productGroupRepository;

    /** @var \Synolia\SyliusAkeneoPlugin\Provider\AkeneoTaskProvider */
    private $taskProvider;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $type;

    /** @var \Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository */
    private $productConfigurationRepository;

    /** @var ProductConfiguration */
    private $productConfiguration;

    /** @var \Synolia\SyliusAkeneoPlugin\Task\AkeneoTaskInterface */
    private $addProductCategoriesTask;

    /** @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface */
    private $dispatcher;

    /** @var \Synolia\SyliusAkeneoPlugin\Service\ProductChannelEnabler */
    private $productChannelEnabler;

    /** @var \Synolia\SyliusAkeneoPlugin\Processor\Product\MainTaxonProcessorInterface */
    private $mainTaxonProcessor;

    /** @var \Synolia\SyliusAkeneoPlugin\Processor\Product\AttributesProcessorInterface */
    private $attributesProcessor;

    /** @var \Synolia\SyliusAkeneoPlugin\Processor\Product\CompleteRequirementProcessorInterface */
    private $completeRequirementProcessor;

    /**
     * @param \Synolia\SyliusAkeneoPlugin\Repository\ProductGroupRepository $productGroupRepository
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ProductFactoryInterface $productFactory,
        ProductRepositoryInterface $productRepository,
        EntityRepository $productGroupRepository,
        AkeneoTaskProvider $taskProvider,
        LoggerInterface $akeneoLogger,
        EntityRepository $productConfigurationRepository,
        EventDispatcherInterface $dispatcher,
        ProductChannelEnabler $productChannelEnabler,
        MainTaxonProcessorInterface $mainTaxonProcessor,
        AttributesProcessorInterface $attributesProcessor,
        CompleteRequirementProcessorInterface $completeRequirementProcessor
    ) {
        $this->entityManager = $entityManager;
        $this->productFactory = $productFactory;
        $this->productRepository = $productRepository;
        $this->productGroupRepository = $productGroupRepository;
        $this->taskProvider = $taskProvider;
        $this->logger = $akeneoLogger;
        $this->productConfigurationRepository = $productConfigurationRepository;
        $this->dispatcher = $dispatcher;
        $this->productChannelEnabler = $productChannelEnabler;
        $this->mainTaxonProcessor = $mainTaxonProcessor;
        $this->attributesProcessor = $attributesProcessor;
        $this->completeRequirementProcessor = $completeRequirementProcessor;
    }

    /**
     * @param ProductModelPayload $payload
     */
    public function __invoke(PipelinePayloadInterface $payload): PipelinePayloadInterface
    {
        $this->logger->debug(self::class);
        $this->type = $payload->getType();
        $this->logger->notice(Messages::createOrUpdate($this->type));
        $this->productConfiguration = $this->productConfigurationRepository->findOneBy([]);
        $this->addProductCategoriesTask = $this->taskProvider->get(AddProductToCategoriesTask::class);

        $query = $this->entityManager->getConnection()->prepare(\sprintf(
            'SELECT id, `values`
             FROM `%s`
             WHERE id IN (%s)
             ORDER BY id ASC',
            ProductModelPayload::TEMP_AKENEO_TABLE_NAME,
            implode(',', $payload->getIds())
        ));

        $query->executeStatement();

        while ($results = $query->fetchAll()) {
            foreach ($results as $result) {
                $resource = \json_decode($result['values'], true);

                try {
                    $this->dispatcher->dispatch(new BeforeProcessingProductEvent($resource));

                    $this->entityManager->beginTransaction();
                    $product = $this->process($payload, $resource);

                    $this->dispatcher->dispatch(new AfterProcessingProductEvent($resource, $product));

                    $this->entityManager->flush();
                    $this->entityManager->commit();

                    $deleteQuery = $this->entityManager->getConnection()->prepare(\sprintf(
                        'DELETE FROM `%s` WHERE id = :id',
                        ProductModelPayload::TEMP_AKENEO_TABLE_NAME,
                    ));
                    $deleteQuery->bindValue('id', $result['id'], ParameterType::INTEGER);
                    $deleteQuery->execute();

                    $this->entityManager->clear();

                    unset($resource, $product);
                } catch (\Throwable $throwable) {
                    $deleteQuery = $this->entityManager->getConnection()->prepare(\sprintf(
                        'DELETE FROM `%s` WHERE id = :id',
                        ProductModelPayload::TEMP_AKENEO_TABLE_NAME,
                    ));
                    $deleteQuery->bindValue('id', $result['id'], ParameterType::INTEGER);
                    $deleteQuery->execute();

                    $this->entityManager->rollback();
                    $this->logger->warning($throwable->getMessage());
                }
            }
        }

        return $payload;
    }

    private function process(PipelinePayloadInterface $payload, array &$resource): ProductInterface
    {
        if ('' === $resource['code'] || null === $resource['code']) {
            throw new \LogicException('Attribute code is missing.');
        }

        $product = $this->productRepository->findOneByCode($resource['code']);

        if (!$product instanceof ProductInterface) {
            /** @var ProductInterface $product */
            $product = $this->productFactory->createNew();
            $product->setCode($resource['code']);

            $this->entityManager->persist($product);
            $this->addOrUpdate($payload, $product, $resource);

            $this->logger->info(Messages::hasBeenCreated($this->type, (string) $product->getCode()));

            return $product;
        }

        $this->addOrUpdate($payload, $product, $resource);
        $this->logger->info(Messages::hasBeenUpdated($this->type, (string) $resource['code']));

        return $product;
    }

    private function addOrUpdate(PipelinePayloadInterface $payload, ProductInterface $product, array &$resource): void
    {
        if (!isset($resource['family'])) {
            throw new \LogicException('Missing family attribute on product');
        }

        $payloadProductGroup = $payload->getAkeneoPimClient()->getFamilyVariantApi()->get(
            $resource['family'],
            $resource['family_variant']
        );

        $numberOfVariationAxis = isset($payloadProductGroup['variant_attribute_sets']) ? \count($payloadProductGroup['variant_attribute_sets']) : 0;

        if (null === $resource['parent'] && $numberOfVariationAxis > self::ONE_VARIATION_AXIS) {
            return;
        }

        $this->completeRequirementProcessor->process($product, $resource);
        $this->attributesProcessor->process($product, $resource);
        $this->addProductGroup($resource, $product);
        $this->mainTaxonProcessor->process($product, $resource);
        $this->linkCategoriesToProduct($payload, $product, $resource);
        $this->updateImages($payload, $resource, $product);

        try {
            $this->productChannelEnabler->enableChannelForProduct($product, $resource);
        } catch (NoAttributeResourcesException $attributeResourcesException) {
        }
    }

    private function linkCategoriesToProduct(PipelinePayloadInterface $payload, ProductInterface $product, array &$resource): void
    {
        $productCategoriesPayload = new ProductCategoriesPayload($payload->getAkeneoPimClient());
        $productCategoriesPayload
            ->setProduct($product)
            ->setCategories($resource['categories'])
        ;
        $this->addProductCategoriesTask->__invoke($productCategoriesPayload);

        unset($productCategoriesPayload);
    }

    private function addProductGroup(array &$resource, ProductInterface $product): void
    {
        $productGroup = $this->productGroupRepository->findOneBy(['productParent' => $resource['parent']]);

        if ($productGroup instanceof ProductGroup && 0 === $this->productGroupRepository->isProductInProductGroup($product, $productGroup)) {
            $productGroup->addProduct($product);
        }
    }

    private function updateImages(PipelinePayloadInterface $payload, array &$resource, ProductInterface $product): void
    {
        if (!$this->productConfiguration instanceof ProductConfiguration) {
            $this->logger->warning(Messages::noConfigurationSet('Product Images', 'Import images'));

            return;
        }

        $productMediaPayload = new ProductMediaPayload($payload->getAkeneoPimClient());
        $productMediaPayload
            ->setProduct($product)
            ->setAttributes($resource['values'])
            ->setProductConfiguration($this->productConfiguration)
        ;
        $imageTask = $this->taskProvider->get(InsertProductImagesTask::class);
        $imageTask->__invoke($productMediaPayload);

        unset($productMediaPayload, $imageTask);
    }
}
