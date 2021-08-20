<?php

declare(strict_types=1);

namespace Tests\Synolia\SyliusAkeneoPlugin\PHPUnit\Task\ProductModel;

use PHPUnit\Framework\Assert;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Synolia\SyliusAkeneoPlugin\Entity\ProductConfiguration;
use Synolia\SyliusAkeneoPlugin\Entity\ProductConfigurationAkeneoImageAttribute;
use Synolia\SyliusAkeneoPlugin\Entity\ProductConfigurationImageMapping;
use Synolia\SyliusAkeneoPlugin\Entity\ProductGroup;
use Synolia\SyliusAkeneoPlugin\Payload\ProductModel\ProductModelPayload;
use Synolia\SyliusAkeneoPlugin\Provider\AkeneoAttributePropertiesProvider;
use Synolia\SyliusAkeneoPlugin\Provider\AkeneoTaskProvider;
use Synolia\SyliusAkeneoPlugin\Task\ProductModel\ProcessProductModelsTask;
use Synolia\SyliusAkeneoPlugin\Task\ProductModel\SetupProductModelTask;
use Synolia\SyliusAkeneoPlugin\Task\ProductModel\TearDownProductModelTask;

/**
 * @internal
 * @coversNothing
 */
final class AddOrUpdateProductModelTaskTest extends AbstractTaskTest
{
    /** @var AkeneoTaskProvider */
    private $taskProvider;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var EntityRepository */
    private $productGroupRepository;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var AkeneoAttributePropertiesProvider $akeneoPropertiesProvider */
        $akeneoPropertiesProvider = $this->getContainer()->get(AkeneoAttributePropertiesProvider::class);
        $akeneoPropertiesProvider->setLoadsAllAttributesAtOnce(true);
        $this->taskProvider = $this->getContainer()->get(AkeneoTaskProvider::class);
        $this->productRepository = $this->getContainer()->get('sylius.repository.product');
        $this->productGroupRepository = $this->getContainer()->get('akeneo.repository.product_group');
        self::assertInstanceOf(AkeneoTaskProvider::class, $this->taskProvider);
    }

    public function testCreateUpdateTask(): void
    {
        $this->prepareConfiguration();

        $productModelPayload = new ProductModelPayload($this->createClient());
        $productModelPayload->setProcessAsSoonAsPossible(false);

        $setupProductModelsTask = $this->taskProvider->get(SetupProductModelTask::class);
        $productModelPayload = $setupProductModelsTask->__invoke($productModelPayload);

        /** @var ProcessProductModelsTask $processProductModelsTask */
        $processProductModelsTask = $this->taskProvider->get(ProcessProductModelsTask::class);
        $productModelPayload = $processProductModelsTask->__invoke($productModelPayload);

        /** @var TearDownProductModelTask $tearDownProductModelTask */
        $tearDownProductModelTask = $this->taskProvider->get(TearDownProductModelTask::class);
        $tearDownProductModelTask->__invoke($productModelPayload);

        /** @var Product $productFinal */
        $productFinal = $this->productRepository->findOneBy(['code' => 'apollon_yellow']);
        Assert::assertInstanceOf(Product::class, $productFinal);
        $this->assertGreaterThan(0, $productFinal->getImages()->count());
        foreach ($productFinal->getImages() as $image) {
            $this->assertFileExists(self::$kernel->getProjectDir() . '/public/media/image/' . $image->getPath());
        }

        $productGroup = $this->productGroupRepository->findOneBy(['productParent' => 'apollon']);
        Assert::assertInstanceOf(ProductGroup::class, $productGroup);
    }

    private function prepareConfiguration(): void
    {
        $productConfiguration = new ProductConfiguration();
        $this->manager->persist($productConfiguration);

        $imageMapping = new ProductConfigurationImageMapping();
        $imageMapping->setAkeneoAttribute('picture');
        $imageMapping->setSyliusAttribute('main');
        $imageMapping->setProductConfiguration($productConfiguration);
        $this->manager->persist($imageMapping);
        $productConfiguration->addProductImagesMapping($imageMapping);

        $imageAttributes = ['picture', 'image'];

        foreach ($imageAttributes as $imageAttribute) {
            $akeneoImageAttribute = new ProductConfigurationAkeneoImageAttribute();
            $akeneoImageAttribute->setAkeneoAttributes($imageAttribute);
            $akeneoImageAttribute->setProductConfiguration($productConfiguration);
            $this->manager->persist($akeneoImageAttribute);
            $productConfiguration->addAkeneoImageAttribute($akeneoImageAttribute);
        }

        $this->manager->flush();
    }
}
