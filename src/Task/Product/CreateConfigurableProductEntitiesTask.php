<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Task\Product;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Product\Factory\ProductVariantFactoryInterface;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Model\ProductOptionValueInterface;
use Sylius\Component\Product\Model\ProductOptionValueTranslationInterface;
use Sylius\Component\Product\Model\ProductVariantTranslation;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Synolia\SyliusAkeneoPlugin\Entity\ProductGroup;
use Synolia\SyliusAkeneoPlugin\Logger\Messages;
use Synolia\SyliusAkeneoPlugin\Manager\ProductOptionManager;
use Synolia\SyliusAkeneoPlugin\Model\PipelinePayloadInterface;
use Synolia\SyliusAkeneoPlugin\Payload\Product\ProductPayload;
use Synolia\SyliusAkeneoPlugin\Payload\Product\ProductVariantMediaPayload;
use Synolia\SyliusAkeneoPlugin\Provider\AkeneoTaskProvider;
use Synolia\SyliusAkeneoPlugin\Repository\ChannelRepository;
use Synolia\SyliusAkeneoPlugin\Repository\ProductGroupRepository;
use Synolia\SyliusAkeneoPlugin\Task\AkeneoTaskInterface;

final class CreateConfigurableProductEntitiesTask extends AbstractCreateProductEntities implements AkeneoTaskInterface
{
    /** @var \Sylius\Component\Resource\Repository\RepositoryInterface */
    private $productOptionRepository;

    /** @var \Sylius\Component\Resource\Repository\RepositoryInterface */
    private $productOptionValueRepository;

    /** @var \Synolia\SyliusAkeneoPlugin\Repository\ProductGroupRepository */
    private $productGroupRepository;

    /** @var \Sylius\Component\Resource\Repository\RepositoryInterface */
    private $productOptionValueTranslationRepository;

    /** @var \Synolia\SyliusAkeneoPlugin\Provider\AkeneoTaskProvider */
    private $taskProvider;

    /** @var LoggerInterface */
    private $logger;

    /** @var int */
    private $updateCount = 0;

    /** @var int */
    private $createCount = 0;

    /** @var string */
    private $type;

    public function __construct(
        EntityManagerInterface $entityManager,
        RepositoryInterface $productVariantRepository,
        RepositoryInterface $productRepository,
        RepositoryInterface $productOptionRepository,
        RepositoryInterface $productOptionValueRepository,
        RepositoryInterface $productOptionValueTranslationRepository,
        ChannelRepository $channelRepository,
        RepositoryInterface $channelPricingRepository,
        RepositoryInterface $localeRepository,
        RepositoryInterface $productConfigurationRepository,
        ProductGroupRepository $productGroupRepository,
        ProductVariantFactoryInterface $productVariantFactory,
        FactoryInterface $channelPricingFactory,
        AkeneoTaskProvider $taskProvider,
        LoggerInterface $akeneoLogger
    ) {
        parent::__construct(
            $entityManager,
            $productVariantRepository,
            $productRepository,
            $channelRepository,
            $channelPricingRepository,
            $localeRepository,
            $productConfigurationRepository,
            $productVariantFactory,
            $channelPricingFactory
        );

        $this->productOptionRepository = $productOptionRepository;
        $this->productOptionValueRepository = $productOptionValueRepository;
        $this->productOptionValueTranslationRepository = $productOptionValueTranslationRepository;
        $this->productGroupRepository = $productGroupRepository;
        $this->taskProvider = $taskProvider;
        $this->logger = $akeneoLogger;
    }

    /**
     * @param \Synolia\SyliusAkeneoPlugin\Payload\Product\ProductPayload $payload
     */
    public function __invoke(PipelinePayloadInterface $payload): PipelinePayloadInterface
    {
        if (!$payload instanceof ProductPayload) {
            return $payload;
        }
        $this->logger->debug(self::class);
        $this->type = 'ConfigurableProduct';
        $this->logger->notice(Messages::createOrUpdate($this->type));

        foreach ($payload->getConfigurableProductPayload()->getProducts() as $configurableProductItem) {
            try {
                $this->entityManager->beginTransaction();

                /** @var ProductInterface $productModel */
                $productModel = $this->productRepository->findOneBy(['code' => $configurableProductItem['parent']]);

                //Skip product variant import if it does not have a parent model on Sylius
                if (!$productModel instanceof ProductInterface || !is_string($productModel->getCode())) {
                    continue;
                }

                $productGroup = $this->productGroupRepository->getProductGroupByProductCode($productModel->getCode());

                if (!$productGroup instanceof ProductGroup) {
                    continue;
                }

                $variationAxes = $productGroup->getVariationAxes();

                if (\count($variationAxes) === 0) {
                    continue;
                }

                $this->processVariations($payload, $configurableProductItem['identifier'], $productModel, $configurableProductItem['values'], $variationAxes);

                $this->entityManager->flush();
                $this->entityManager->commit();
            } catch (\Throwable $throwable) {
                $this->entityManager->rollback();
                $this->logger->warning($throwable->getMessage());
            }
        }

        $this->logger->notice(Messages::countCreateAndUpdate($this->type, $this->createCount, $this->updateCount));

        return $payload;
    }

    private function processVariations(
        ProductPayload $payload,
        string $variantCode,
        ProductInterface $productModel,
        array $attributes,
        array $variationAxes
    ): void {
        foreach ($attributes as $attributeCode => $values) {
            /*
             * Skip attributes that aren't variation axes.
             * Variation axes value will be created as option for the product
             */
            if (!\in_array($attributeCode, $variationAxes, true)) {
                continue;
            }

            /** @var ProductOptionInterface $productOption */
            $productOption = $this->productOptionRepository->findOneBy(['code' => $attributeCode]);

            //We cannot create the variant if the option does not exists
            if (!$productOption instanceof ProductOptionInterface) {
                continue;
            }

            if ($productModel->hasOption($productOption)) {
                $productModel->addOption($productOption);
            }

            $productVariant = $this->getOrCreateEntity($variantCode, $productModel);

            $this->setProductOptionValues($productVariant, $productOption, $values);
            $this->updateImages($payload, $attributes, $productVariant);
            $this->setProductPrices($productVariant, $attributes);
        }
    }

    private function getLocales(): iterable
    {
        /** @var LocaleInterface[] $locales */
        $locales = $this->localeRepository->findAll();

        foreach ($locales as $locale) {
            yield $locale->getCode();
        }
    }

    private function setProductOptionValues(
        ProductVariantInterface $productVariant,
        ProductOptionInterface $productOption,
        array $values
    ): void {
        foreach ($values as $optionValue) {
            $productOptionValue = $this->productOptionValueRepository->findOneBy([
                'option' => $productOption,
                'code' => ProductOptionManager::getOptionValueCodeFromProductOption($productOption, $optionValue['data']),
            ]);

            if (!$productOptionValue instanceof ProductOptionValueInterface) {
                continue;
            }

            //Product variant already have this value
            if (!$productVariant->hasOptionValue($productOptionValue)) {
                $productVariant->addOptionValue($productOptionValue);
            }

            foreach ($this->getLocales() as $locale) {
                /** @var \Sylius\Component\Product\Model\ProductOptionValueTranslationInterface $productOptionValueTranslation */
                $productOptionValueTranslation = $this->productOptionValueTranslationRepository->findOneBy([
                    'translatable' => $productOptionValue,
                    'locale' => $locale,
                ]);

                if (!$productOptionValueTranslation instanceof ProductOptionValueTranslationInterface) {
                    continue;
                }

                $productVariantTranslation = $productVariant->getTranslation($locale);

                if (!$productVariantTranslation instanceof ProductVariantTranslationInterface) {
                    $productVariantTranslation = new ProductVariantTranslation();
                    $productVariantTranslation->setLocale($locale);
                    $productVariant->addTranslation($productVariantTranslation);
                }

                $productVariantTranslation->setName($productOptionValueTranslation->getValue());
            }
        }
    }

    private function updateImages(ProductPayload $payload, array $resource, ProductVariantInterface $productVariant): void
    {
        $productVariantMediaPayload = new ProductVariantMediaPayload($payload->getAkeneoPimClient());
        $productVariantMediaPayload
            ->setProductVariant($productVariant)
            ->setAttributes($resource)
        ;
        $imageTask = $this->taskProvider->get(InsertProductVariantImagesTask::class);
        $imageTask->__invoke($productVariantMediaPayload);
    }

    private function getOrCreateEntity(string $variantCode, ProductInterface $productModel): ProductVariantInterface
    {
        $productVariant = $this->productVariantRepository->findOneBy(['code' => $variantCode]);

        if (!$productVariant instanceof ProductVariantInterface) {
            /** @var ProductVariantInterface $productVariant */
            $productVariant = $this->productVariantFactory->createForProduct($productModel);
            $productVariant->setCode($variantCode);

            ++$this->createCount;
            $this->logger->info(Messages::hasBeenCreated($this->type, (string) $productVariant->getCode()));

            $this->entityManager->persist($productVariant);

            return $productVariant;
        }

        ++$this->updateCount;
        $this->logger->info(Messages::hasBeenUpdated($this->type, (string) $productVariant->getCode()));

        return $productVariant;
    }
}
