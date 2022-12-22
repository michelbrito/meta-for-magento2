<?php
/**
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Meta\Catalog\Observer\Product;

use Exception;
use Meta\BusinessExtension\Helper\FBEHelper;
use Meta\BusinessExtension\Helper\GraphAPIAdapter;
use Meta\BusinessExtension\Model\System\Config as SystemConfig;
use Meta\Catalog\Model\Product\Feed\Method\BatchApi;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class SaveAfter implements ObserverInterface
{
    /**
     * @var SystemConfig
     */
    private $systemConfig;

    /**
     * @var FBEHelper
     */
    protected $fbeHelper;

    /**
     * @var BatchApi
     */
    protected $batchApi;

    /**
     * @var GraphAPIAdapter
     */
    protected $graphApiAdapter;

    /**
     * @param SystemConfig $systemConfig
     * @param FBEHelper $helper
     * @param BatchApi $batchApi
     * @param GraphAPIAdapter $graphApiAdapter
     */
    public function __construct(
        SystemConfig $systemConfig,
        FBEHelper $helper,
        BatchApi $batchApi,
        GraphAPIAdapter $graphApiAdapter
    ) {
        $this->systemConfig = $systemConfig;
        $this->fbeHelper = $helper;
        $this->batchApi = $batchApi;
        $this->graphApiAdapter = $graphApiAdapter;
    }

    /**
     * Call an API to product save from facebook catalog
     * after save product from Magento
     *
     * @todo Take into consideration current store scope
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        if (!($this->systemConfig->isActiveExtension() && $this->systemConfig->isActiveIncrementalProductUpdates())) {
            return;
        }

        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();
        if (!$product->getId()) {
            return;
        }

        if ($product->getSendToFacebook() === \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_NO) {
            return;
        }

        $productStoreId = $product->getStoreId();
        $storeId = $this->fbeHelper->getStore()->getId();
        $product->setStoreId($storeId);

        // @todo implement error handling/logging for invalid access token and other non-happy path scenarios
        // @todo implement batch API status check
        // @todo implement async call

        try {
            $catalogId = $this->systemConfig->getCatalogId($storeId);
            $requestData = $this->batchApi->buildRequestForIndividualProduct($product);
            $this->graphApiAdapter->catalogBatchRequest($catalogId, [$requestData]);
        } catch (Exception $e) {
            $this->fbeHelper->logException($e);
        }

        $product->setStoreId($productStoreId);
    }
}