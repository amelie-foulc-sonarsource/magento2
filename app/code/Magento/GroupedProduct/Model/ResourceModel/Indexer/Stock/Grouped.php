<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Grouped Products Stock Status Indexer Resource Model
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 */
namespace Magento\GroupedProduct\Model\ResourceModel\Indexer\Stock;

use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\CatalogInventory\Model\Indexer\Stock\Action\Full;

/**
 * Stock indexer for grouped product.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Grouped extends \Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock\DefaultStock
{
    /**
     * @var \Magento\Indexer\Model\ResourceModel\FrontendResource
     */
    private $indexerStockFrontendResource;

    /**
     * Class constructor
     *
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Framework\Indexer\Table\StrategyInterface $tableStrategy
     * @param \Magento\Eav\Model\Config $eavConfig
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param string $connectionName
     * @param null|\Magento\Indexer\Model\Indexer\StateFactory $stateFactory
     * @param null|\Magento\Indexer\Model\ResourceModel\FrontendResource $indexerStockFrontendResource
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Framework\Indexer\Table\StrategyInterface $tableStrategy,
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        $connectionName = null,
        \Magento\Indexer\Model\Indexer\StateFactory $stateFactory = null,
        \Magento\Indexer\Model\ResourceModel\FrontendResource $indexerStockFrontendResource = null
    ) {
        parent::__construct($context, $tableStrategy, $eavConfig, $scopeConfig, $connectionName, $stateFactory);
        $this->indexerStockFrontendResource = $indexerStockFrontendResource ?: ObjectManager::getInstance()->get(
            \Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock\FrontendResource::class
        );
    }

    /**
     * Get the select object for get stock status by grouped product ids
     *
     * @param int|array $entityIds
     * @param bool $usePrimaryTable use primary or temporary index table
     * @return \Magento\Framework\DB\Select
     */
    protected function _getStockStatusSelect($entityIds = null, $usePrimaryTable = false)
    {
        $connection = $this->getConnection();
        $table = $this->getActionType() === Full::ACTION_TYPE
            ? $this->getMainTable()
            : $this->indexerStockFrontendResource->getMainTable();
        $idxTable = $usePrimaryTable ? $table : $this->getIdxTable();
        $metadata = $this->getMetadataPool()->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class);
        $select = parent::_getStockStatusSelect($entityIds, $usePrimaryTable);
        $select->reset(
            \Magento\Framework\DB\Select::COLUMNS
        )->columns(
            ['e.entity_id', 'cis.website_id', 'cis.stock_id']
        )->joinLeft(
            ['l' => $this->getTable('catalog_product_link')],
            'e.' . $metadata->getLinkField() . ' = l.product_id AND l.link_type_id=' .
            \Magento\GroupedProduct\Model\ResourceModel\Product\Link::LINK_TYPE_GROUPED,
            []
        )->joinLeft(
            ['le' => $this->getTable('catalog_product_entity')],
            'le.entity_id = l.linked_product_id',
            []
        )->joinLeft(
            ['i' => $idxTable],
            'i.product_id = l.linked_product_id AND cis.website_id = i.website_id AND cis.stock_id = i.stock_id',
            []
        )->columns(
            ['qty' => new \Zend_Db_Expr('0')]
        );

        $statusExpression = $this->getStatusExpression($connection);

        $optExpr = $connection->getCheckSql("le.required_options = 0", 'i.stock_status', 0);
        $stockStatusExpr = $connection->getLeastSql(["MAX({$optExpr})", "MIN({$statusExpression})"]);

        $select->columns(['status' => $stockStatusExpr]);

        if ($entityIds !== null) {
            $select->where('e.entity_id IN(?)', $entityIds);
        }

        return $select;
    }
}
