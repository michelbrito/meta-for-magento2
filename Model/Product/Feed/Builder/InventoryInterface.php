<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 */
namespace Facebook\BusinessExtension\Model\Product\Feed\Builder;

use Magento\Catalog\Model\Product;

interface InventoryInterface
{
    const STATUS_IN_STOCK = 'in stock';

    const STATUS_OUT_OF_STOCK = 'out of stock';

    /**
     * @param Product $product
     * @return $this
     */
    public function initInventoryForProduct(Product $product);

    /**
     * @return string
     */
    public function getAvailability();

    /**
     * @return int
     */
    public function getInventory();
}
