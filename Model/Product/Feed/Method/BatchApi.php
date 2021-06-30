<?php /** @noinspection PhpUndefinedFieldInspection */
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 */
namespace Facebook\BusinessExtension\Model\Product\Feed\Method;

use Exception;
use Facebook\BusinessExtension\Helper\FBEHelper;
use Facebook\BusinessExtension\Helper\GraphAPIAdapter;
use Facebook\BusinessExtension\Model\Product\Feed\Builder;
use Facebook\BusinessExtension\Model\Product\Feed\ProductRetriever\Configurable as ConfigurableProductRetriever;
use Facebook\BusinessExtension\Model\Product\Feed\ProductRetriever\Simple as SimpleProductRetriever;
use Facebook\BusinessExtension\Model\Product\Feed\ProductRetrieverInterface;
use Facebook\BusinessExtension\Model\System\Config as SystemConfig;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;

class BatchApi
{
    const ATTR_METHOD = 'method';
    const ATTR_UPDATE = 'UPDATE';
    const ATTR_DATA = 'data';

    // Process only the maximum allowed by API per request
    const BATCH_MAX = 4999;

    /**
     * @var FBEHelper
     */
    protected $fbeHelper;

    /**
     * @var GraphAPIAdapter
     */
    protected $graphApiAdapter;

    /**
     * @var SystemConfig
     */
    protected $systemConfig;

    /**
     * @var ProductRetrieverInterface[]
     */
    protected $productRetrievers;

    /**
     * @var Builder
     */
    protected $builder;

    /**
     * @param FBEHelper $helper
     * @param GraphAPIAdapter $graphApiAdapter
     * @param SystemConfig $systemConfig
     * @param SimpleProductRetriever $simpleProductRetriever
     * @param ConfigurableProductRetriever $configurableProductRetriever
     * @param Builder $builder
     */
    public function __construct(
        FBEHelper $helper,
        GraphAPIAdapter $graphApiAdapter,
        SystemConfig $systemConfig,
        SimpleProductRetriever $simpleProductRetriever,
        ConfigurableProductRetriever $configurableProductRetriever,
        Builder $builder
    ) {
        $this->fbeHelper = $helper;
        $this->graphApiAdapter = $graphApiAdapter;
        $this->systemConfig = $systemConfig;
        $this->productRetrievers = [
            $simpleProductRetriever,
            $configurableProductRetriever
        ];
        $this->builder = $builder;
    }

    /**
     * @param Product $product
     * @param string $method
     * @return array
     * @throws LocalizedException
     */
    protected function buildProductRequest(Product $product, $method = self::ATTR_UPDATE)
    {
        return [
            self::ATTR_METHOD => $method,
            self::ATTR_DATA => $this->builder->buildProductEntry($product)
        ];
    }

    /**
     * @param Product $product
     * @param string $method
     * @return array
     * @throws LocalizedException
     */
    public function buildRequestForIndividualProduct(Product $product, $method = self::ATTR_UPDATE)
    {
        $this->builder->setStoreId($product->getStoreId());
        return $this->buildProductRequest($product, $method);
    }

    /**
     * @param null $storeId
     * @param null $accessToken
     * @return array
     * @throws Exception
     */
    public function generateProductRequestData($storeId = null, $accessToken = null)
    {
        $this->builder->setStoreId($this->storeId);
        $this->graphApiAdapter->setDebugMode($this->systemConfig->isDebugMode($storeId))
            ->setAccessToken($accessToken ?? $this->systemConfig->getAccessToken($storeId));

        $catalogId = $this->systemConfig->getCatalogId($storeId);

        $currentBatch = 1;
        $requests = [];
        $responses = [];
        $exceptions = 0;
        foreach ($this->productRetrievers as $productRetriever) {
            $offset = 0;
            $productRetriever->setStoreId($storeId);
            $limit = $productRetriever->getLimit();
            do {
                $products = $productRetriever->retrieve($offset);
                $offset += $limit;
                if (empty($products)) {
                    break;
                }

                foreach ($products as $product) {
                    try {
                        $requests[] = $this->buildProductRequest($product);
                    } catch (Exception $e) {
                        $exceptions++;
                        // Don't overload the logs, log the first 3 exceptions
                        if ($exceptions <= 3) {
                            $this->fbeHelper->logException($e);
                        }
                        // If it looks like a systemic failure : stop feed generation
                        if ($exceptions > 100) {
                            throw $e;
                        }
                    }

                    if (count($requests) === self::BATCH_MAX) {
                        $this->fbeHelper->log(
                            sprintf('Pushing batch %d with %d products', $currentBatch, count($requests))
                        );
                        $response = $this->graphApiAdapter->catalogBatchRequest($catalogId, $requests);
                        $this->fbeHelper->log('Product push response ' . json_encode($response));
                        $responses[] = $response;
                        unset($requests);
                        $currentBatch++;
                    }
                }
            } while (true);
        }

        if (!empty($requests)) {
            $this->fbeHelper->log(sprintf('Pushing batch %d with %d products', $currentBatch, count($requests)));
            $response = $this->graphApiAdapter->catalogBatchRequest($catalogId, $requests);
            $this->fbeHelper->log('Product push response ' . json_encode($response));
            $responses[] = $response;
        }

        return $responses;
    }
}
