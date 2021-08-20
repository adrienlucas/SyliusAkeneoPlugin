<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Payload\Product;

use Akeneo\Pim\ApiClient\Pagination\ResourceCursorInterface;
use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientInterface;
use Synolia\SyliusAkeneoPlugin\Command\Context\CommandContextInterface;
use Synolia\SyliusAkeneoPlugin\Payload\AbstractPayload;

final class ProductPayload extends AbstractPayload
{
    public const TEMP_AKENEO_TABLE_NAME = 'tmp_akeneo_products';

    public const BATCH_COMMAND_NAME = 'akeneo:batch:products';

    /** @var \Akeneo\Pim\ApiClient\Pagination\Page|ResourceCursorInterface|null */
    private $resources;

    /** @var ProductItemPayload */
    private $simpleProductPayload;

    /** @var ProductItemPayload */
    private $configurableProductPayload;

    public function __construct(AkeneoPimEnterpriseClientInterface $akeneoPimClient, ?CommandContextInterface $commandContext = null)
    {
        parent::__construct($akeneoPimClient, $commandContext);

        $this->simpleProductPayload = new ProductItemPayload($akeneoPimClient);
        $this->configurableProductPayload = new ProductItemPayload($akeneoPimClient);

        $this->setTmpTableName(self::TEMP_AKENEO_TABLE_NAME);
        $this->setCommandName(self::BATCH_COMMAND_NAME);
    }

    /**
     * @return \Akeneo\Pim\ApiClient\Pagination\Page|\Akeneo\Pim\ApiClient\Pagination\ResourceCursorInterface|null
     */
    public function getResources()
    {
        return $this->resources;
    }

    /**
     * @param mixed $resources
     */
    public function setResources($resources): void
    {
        $this->resources = $resources;
    }

    public function getSimpleProductPayload(): ProductItemPayload
    {
        return $this->simpleProductPayload;
    }

    public function getConfigurableProductPayload(): ProductItemPayload
    {
        return $this->configurableProductPayload;
    }
}
