<?php

namespace Reviewscouk\Reviews\Controller\Index;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\ResultFactory;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Store\Model\StoreManagerInterface;
use Reviewscouk\Reviews\Helper\Config;

class Feed implements HttpGetActionInterface
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Image $image,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly Configurable $configurableType,
        private readonly ResultFactory $resultFactory,
        private readonly Grouped $groupedType
    ) {
    }

    public function execute()
    {
        // Set timelimit to 0 to avoid timeouts when generating feed.
        ob_start();
        set_time_limit(0);

        $store = $this->storeManager->getStore();

        $productFeedEnabled = $this->config->isProductFeedEnabled($store->getId());
        if ($productFeedEnabled) {
            // TODO:- Implement caching of Feed
            $productFeed = "<?xml version='1.0'?>
                    <rss version ='2.0' xmlns:g='http://base.google.com/ns/1.0'>
                    <channel>
                    <title><![CDATA[" . $store->getName() . "]]></title>
                    <link>" . $store->getBaseUrl() . "</link>";

            $products = $this->getProductCollection();

            foreach ($products as $product) {
                $groupedParentId = null;
                $configurableParentId = null;
                $objectManager = ObjectManager::getInstance();

                $groupedParentId = $this->groupedType->getParentIdsByChild($product->getId());
                $configurableParentId = $this->configurableType->getParentIdsByChild($product->getId());

                $parentId = null;

                if (isset($groupedParentId[0])) {
                    $parentId = $groupedParentId[0];
                } else if (isset($configurableParentId[0])) {
                    $parentId = $configurableParentId[0];
                }

                // Load image url via helper.
                $productImageUrl = $this->image->init($product, 'product_page_image_large')->getUrl();
                $imageLink = $productImageUrl;
                $productUrl = $product->getProductUrl();

                if (isset($parentId)) {
                    $parentProduct = $objectManager->create(Product::class)->load($parentId);

                    $parentProductImageUrl = $this->image->init($parentProduct, 'product_page_image_large')->getUrl();
                    $validVariantImage = $this->validateImageUrl($productImageUrl);

                    if (!$validVariantImage) {
                        $imageLink = $parentProductImageUrl;
                    }

                    $productUrl = $parentProduct->getProductUrl();
                }

                $brand = $product->hasData('manufacturer')
                    ? $product->getAttributeText('manufacturer')
                    : ($product->hasData('brand') ? $product->getAttributeText('brand') : 'Not Available');
                $price = $product->getPrice();
                $finalPrice = $product->getFinalPrice();

                $productFeed .= "<item>
                        <g:id><![CDATA[" . $product->getSku() . "]]></g:id>
                        <title><![CDATA[" . $product->getName() . "]]></title>
                        <link><![CDATA[" . $productUrl . "]]></link>
                        <g:price>" . (!empty($price) ? number_format($price, 2) . " " . $store->getCurrentCurrency()->getCode() : '') . "</g:price>
                        <g:sale_price>" . (!empty($finalPrice) ? number_format($finalPrice, 2) . " " . $store->getCurrentCurrency()->getCode() : '') . "</g:sale_price>
                        <description><![CDATA[]]></description>
                        <g:condition>new</g:condition>
                        <g:image_link><![CDATA[" . $imageLink . "]]></g:image_link>
                        <g:brand><![CDATA[" . $brand . "]]></g:brand>
                        <g:mpn><![CDATA[" . ($product->hasData('mpn') ? $product->getData('mpn') : $product->getSku()) . "]]></g:mpn>
                        <g:gtin><![CDATA[" . ($product->hasData('gtin') ? $product->getData('gtin') : ($product->hasData('upc') ? $product->getData('upc') : '')) . "]]></g:gtin>
                        <g:product_type><![CDATA[" . $product->getTypeID() . "]]></g:product_type>
                        <g:shipping>
                        <g:country>UK</g:country>
                        <g:service>Standard Free Shipping</g:service>
                        <g:price>0 GBP</g:price>
                        </g:shipping>";

                $categoryCollection = $product->getCategoryCollection();
                if (count($categoryCollection) > 0) {
                    foreach ($categoryCollection as $category) {
                        $productFeed .= '<g:google_product_category><![CDATA[' . $category->getName() . ']]></g:google_product_category>';
                    }
                }

                $stock = $this->stockRegistry->getStockItem(
                    $product->getId(),
                    $product->getStore()->getWebsiteId()
                );

                if ($stock->getIsInStock()) {
                    $productFeed .= '<g:availability>in stock</g:availability>';
                } else {
                    $productFeed .= '<g:availability>out of stock</g:availability>';
                }

                $productFeed .= '</item>';
                $parentProduct = null;
            }

            $productFeed .= '</channel></rss>';

            // TODO:- Implement caching of feed

            $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $result->setContents($productFeed);

            return $result;
        } else {
            print 'Product Feed is disabled.';
        }
    }

    private function getProductCollection()
    {
        $collection = $this->productCollectionFactory->create();
        $collection
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addAttributeToSelect(['name', 'manufacturer', 'gtin', 'brand', 'image', 'upc', 'mpn'])
            ->addUrlRewrite();
        return $collection;
    }

    private function validateImageUrl($imageUrl): bool
    {
        if (str_contains($imageUrl, 'Magento_Catalog/images/product/placeholder/image.jpg')) {
            return false;
        }

        return true;
    }
}
