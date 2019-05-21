<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Scriptlodge\AdvancedPricingImportExport\Model\Export;

use Magento\ImportExport\Model\Export;
use Magento\Store\Model\Store;
use Magento\CatalogImportExport\Model\Import\Product as ImportProduct;
use Magento\AdvancedPricingImportExport\Model\Import\AdvancedPricing as ImportAdvancedPricing;
use Magento\Catalog\Model\Product as CatalogProduct;

/**
 * Export Advanced Pricing
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AdvancedPricing extends \Magento\AdvancedPricingImportExport\Model\Export\AdvancedPricing
{
    const ENTITY_ADVANCED_PRICING = 'advanced_pricing';

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Product\StoreResolver
     */
    protected $_storeResolver;

    /**
     * @var \Magento\Customer\Api\GroupRepositoryInterface
     */
    protected $_groupRepository;

    /**
     * @var string
     */
    protected $_entityTypeCode;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resource;

    /**
     * @var int
     */
    protected $_passTierPrice = 0;

    /**
     * List of items websites
     *
     * @var array
     */
    protected $_priceWebsite = [
        ImportAdvancedPricing::COL_TIER_PRICE_WEBSITE,
    ];

    /**
     * List of items customer groups
     *
     * @var array
     */
    protected $_priceCustomerGroup = [
        ImportAdvancedPricing::COL_TIER_PRICE_CUSTOMER_GROUP,
    ];



    /**
     * Creating export-formatted row from tier price.
     *
     * @param array $tierPriceData Tier price information.
     *
     * @return array Formatted for export tier price information.
     */
    private function createExportRow(array $tierPriceData): array
    {
        //List of columns to display in export row.
        $exportRow = $this->templateExportData;

        foreach (array_keys($exportRow) as $keyTemplate) {
            if (array_key_exists($keyTemplate, $tierPriceData)) {
                if (in_array($keyTemplate, $this->_priceWebsite)) {
                    //If it's website column then getting website code.
                    $exportRow[$keyTemplate] = $this->_getWebsiteCode(
                        $tierPriceData[$keyTemplate]
                    );
                } elseif (in_array($keyTemplate, $this->_priceCustomerGroup)) {
                    //If it's customer group column then getting customer
                    //group name by ID.
                    $exportRow[$keyTemplate] = $this->_getCustomerGroupById(
                        $tierPriceData[$keyTemplate],
                        $tierPriceData[ImportAdvancedPricing::VALUE_ALL_GROUPS]
                    );
                    unset($exportRow[ImportAdvancedPricing::VALUE_ALL_GROUPS]);
                } elseif ($keyTemplate
                    === ImportAdvancedPricing::COL_TIER_PRICE
                ) {
                    //If it's price column then getting value and type
                    //of tier price.
                    $exportRow[$keyTemplate]
                        = $tierPriceData[ImportAdvancedPricing::COL_TIER_PRICE_PERCENTAGE_VALUE]
                        ? $tierPriceData[ImportAdvancedPricing::COL_TIER_PRICE_PERCENTAGE_VALUE]
                        : $tierPriceData[ImportAdvancedPricing::COL_TIER_PRICE];
                    $exportRow[ImportAdvancedPricing::COL_TIER_PRICE_TYPE]
                        = $this->tierPriceTypeValue($tierPriceData);
                } else {
                    //Any other column just goes as is.
                    $exportRow[$keyTemplate] = $tierPriceData[$keyTemplate];
                }
            }
        }

        return $exportRow;
    }

    /**
     * Get export data for collection
     *
     * @return array|mixed
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    protected function getExportData()
    {
        if ($this->_passTierPrice) {
            return [];
        }

        $exportData = [];
        try {
            $productsByStores = $this->loadCollection();
            if (!empty($productsByStores)) {
                $linkField = $this->getProductEntityLinkField();
                $productLinkIds = [];

                foreach ($productsByStores as $product) {
                    $productLinkIds[array_pop($product)[$linkField]] = true;
                }
                $productLinkIds = array_keys($productLinkIds);
                $tierPricesData = $this->fetchTierPrices($productLinkIds);
                $exportData = $this->prepareExportData(
                    $productsByStores,
                    $tierPricesData
                );
                if (!empty($exportData)) {
                    asort($exportData);
                }
            }
        } catch (\Throwable $e) {
            $this->_logger->critical($e);
        }

        return $exportData;
    }

    /**
     * Prepare data for export.
     *
     * @param array $productsData Products to export.
     * @param array $tierPricesData Their tier prices.
     *
     * @return array Export rows to display.
     */
    private function prepareExportData(
        array $productsData,
        array $tierPricesData
    ): array {
        //Assigning SKUs to tier prices data.
        $productLinkIdToSkuMap = [];
        foreach ($productsData as $productData) {

            if(isset($productData[Store::DEFAULT_STORE_ID])) {
                $productLinkIdToSkuMap[$productData[Store::DEFAULT_STORE_ID][$this->getProductEntityLinkField()]]
                    = $productData[Store::DEFAULT_STORE_ID]['sku'];
            }
        }

        //Adding products' SKUs to tier price data.
        $linkedTierPricesData = [];
        foreach ($tierPricesData as $tierPriceData) {
            $sku = (isset($productLinkIdToSkuMap[$tierPriceData['product_link_id']]))?$productLinkIdToSkuMap[$tierPriceData['product_link_id']]:"";
            $linkedTierPricesData[] = array_merge(
                $tierPriceData,
                [ImportAdvancedPricing::COL_SKU => $sku]
            );
        }

        //Formatting data for export.
        $customExportData = [];
        foreach ($linkedTierPricesData as $row) {
            $customExportData[] = $this->createExportRow($row);
        }

        return $customExportData;
    }




    /**
     * Load tier prices for given products.
     *
     * @param string[] $productIds Link IDs of products to find tier prices for.
     *
     * @return array Tier prices data.
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function fetchTierPrices(array $productIds): array
    {
        if (empty($productIds)) {
            throw new \InvalidArgumentException(
                'Can only load tier prices for specific products'
            );
        }

        $pricesTable = ImportAdvancedPricing::TABLE_TIER_PRICE;
        $exportFilter = null;
        $priceFromFilter = null;
        $priceToFilter = null;
        if (isset($this->_parameters[Export::FILTER_ELEMENT_GROUP])) {
            $exportFilter = $this->_parameters[Export::FILTER_ELEMENT_GROUP];
        }
        $productEntityLinkField = $this->getProductEntityLinkField();
        $selectFields = [
            ImportAdvancedPricing::COL_TIER_PRICE_WEBSITE => 'ap.website_id',
            ImportAdvancedPricing::VALUE_ALL_GROUPS => 'ap.all_groups',
            ImportAdvancedPricing::COL_TIER_PRICE_CUSTOMER_GROUP => 'ap.customer_group_id',
            ImportAdvancedPricing::COL_TIER_PRICE_QTY => 'ap.qty',
            ImportAdvancedPricing::COL_TIER_PRICE => 'ap.value',
            ImportAdvancedPricing::COL_TIER_PRICE_PERCENTAGE_VALUE => 'ap.percentage_value',
            'product_link_id' => 'ap.' .$productEntityLinkField,
        ];
        if ($exportFilter && array_key_exists('tier_price', $exportFilter)) {
            if (!empty($exportFilter['tier_price'][0])) {
                $priceFromFilter = $exportFilter['tier_price'][0];
            }
            if (!empty($exportFilter['tier_price'][1])) {
                $priceToFilter = $exportFilter['tier_price'][1];
            }
        }

        $select = $this->_connection->select()
            ->from(
                ['ap' => $this->_resource->getTableName($pricesTable)],
                $selectFields
            )
            ->where(
                'ap.'.$productEntityLinkField.' IN (?)',
                $productIds
            );

        if ($priceFromFilter !== null) {
            $select->where('ap.value >= ?', $priceFromFilter);
        }
        if ($priceToFilter !== null) {
            $select->where('ap.value <= ?', $priceToFilter);
        }
        if ($priceFromFilter || $priceToFilter) {
            $select->orWhere('ap.percentage_value IS NOT NULL');
        }

        return $this->_connection->fetchAll($select);
    }

    /**
     * Check type for tier price.
     *
     * @param array $tierPriceData
     * @return string
     */
    private function tierPriceTypeValue(array $tierPriceData): string
    {
        return $tierPriceData[ImportAdvancedPricing::COL_TIER_PRICE_PERCENTAGE_VALUE]
            ? ImportAdvancedPricing::TIER_PRICE_TYPE_PERCENT
            : ImportAdvancedPricing::TIER_PRICE_TYPE_FIXED;
    }

}
