<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Processor\Product;

use Sylius\Component\Core\Model\ProductInterface;

interface AttributesProcessorInterface
{
    public function process(ProductInterface $product, array $resource): void;
}
