<?php

namespace BigBridge\ProductImport\Model\Resource\Storage;

use BigBridge\ProductImport\Api\Data\BundleProduct;
use BigBridge\ProductImport\Api\Data\Product;
use BigBridge\ProductImport\Model\Persistence\Magento2DbConnection;
use BigBridge\ProductImport\Model\Resource\MetaData;

/**
 * @author Patrick van Bergen
 */
class BundleStorage
{
    /** @var  Magento2DbConnection */
    protected $db;

    /** @var  MetaData */
    protected $metaData;

    public function __construct(
        Magento2DbConnection $db,
        MetaData $metaData)
    {
        $this->db = $db;
        $this->metaData = $metaData;
    }

    /**
     * @param Product[] $updateProducts
     */
    public function performTypeSpecificStorage(array $updateProducts)
    {
        $changedProducts = $this->detectChangedProducts($updateProducts);
        $this->removeOptions($changedProducts);
        $this->createOptions($changedProducts);
    }

    /**
     * @param BundleProduct[] $products
     */
    public function createOptions(array $products)
    {
        foreach ($products as $product) {

            if (($options = $product->getOptions()) !== null) {
                foreach ($options as $i => $option) {

                    $this->db->execute("
                    INSERT INTO `{$this->metaData->bundleOptionTable}`
                    SET 
                        `parent_id` = ?,
                        `required` = ?,
                        `position` = ?,
                        `type` = ?
                ", [
                        $product->id,
                        (int)$option->isRequired(),
                        $i + 1,
                        $option->getInputType()]);

                    $option->id = $this->db->getLastInsertId();

                    foreach ($option->getSelections() as $j => $selection) {

                        $this->db->execute("
                        INSERT INTO `{$this->metaData->bundleSelectionTable}`
                        SET
                            `option_id` = ?,
                            `parent_product_id` = ?,
                            `product_id` = ?,
                            `position` = ?,
                            `is_default` = ?,
                            `selection_price_type` = ?,
                            `selection_price_value` = ?,
                            `selection_qty` = ?,
                            `selection_can_change_qty` = ?
                    ", [
                            $option->id,
                            $product->id,
                            $selection->getProductId(),
                            $j + 1,
                            (int)$selection->isDefault(),
                            $selection->getPriceType(),
                            $selection->getPriceValue(),
                            $selection->getQuantity(),
                            (int)$selection->isCanChangeQuantity()]);
                    }
                }
            }

            foreach ($product->getStoreViews() as $storeView) {
                foreach ($storeView->getOptionInformations() as $optionInformation) {

                    $this->db->execute("
                        INSERT INTO `{$this->metaData->bundleOptionValueTable}`
                        SET
                            `option_id` = ?,
                            `store_id` = ?,
                            `title` = ? 
                    ", [
                        $optionInformation->getOption()->id,
                        $storeView->getStoreViewId(),
                        $optionInformation->getTitle()
                    ]);
                }
            }
        }
    }

    /**
     * @param Product[] $products
     */
    public function removeOptions(array $products)
    {
        $productIds = array_column($products, 'id');

        $this->db->deleteMultiple($this->metaData->bundleOptionTable, 'parent_id', $productIds);
    }

    /**
     * @param BundleProduct[] $products
     * @return BundleProduct[]
     */
    protected function detectChangedProducts(array $products): array
    {
        if (empty($products)) {
            return [];
        }

        // fetch all data that forms the option, per table

        $productIds = array_column($products, 'id');

        $optionInfo = $this->db->fetchAllAssoc("
            SELECT `option_id`, `parent_id`, `required`, `type`
            FROM `{$this->metaData->bundleOptionTable}` 
            WHERE `parent_id` IN (" . $this->db->getMarks($productIds) . ")
            ORDER BY `position`
        ", $productIds);

        if (empty($optionInfo)) {

            $option2product = [];
            $selectionInfo = [];
            $titleInfo = [];

        } else {

            $optionIds = array_column($optionInfo, 'option_id');

            $option2product = [];
            foreach ($optionInfo as $optionData) {
                $option2product[$optionData['option_id']] = $optionData['parent_id'];
            }

            $selectionInfo = $this->db->fetchAllAssoc("
                SELECT `parent_product_id`, `product_id`, `is_default`, `selection_price_type`, `selection_price_value`, `selection_qty`, `selection_can_change_qty`
                FROM `{$this->metaData->bundleSelectionTable}`
                WHERE `option_id` IN (" . $this->db->getMarks($optionIds) . ")
                ORDER BY `selection_id`
            ", $optionIds);

            $titleInfo = $this->db->fetchAllAssoc("
                SELECT `option_id`, `title`
                FROM `{$this->metaData->bundleOptionValueTable}` 
                WHERE `option_id` IN (" . $this->db->getMarks($optionIds) . ")
                ORDER BY `value_id`
            ", $optionIds);
        }

        // create a string with all option fields, per product id

        $productInfo = [];
        foreach ($products as $product) {
            $productInfo[$product->id] = '';
        }

        foreach ($optionInfo as $optionData) {
            $productInfo[$optionData['parent_id']] .= '*' . $optionData['required'] . '-' . $optionData['type'];
        }
        foreach ($selectionInfo as $selectionData) {
            $productInfo[$selectionData['parent_product_id']] .= '*' . $selectionData['product_id'] .
                '-' . $selectionData['is_default'] .
                '-' . $selectionData['selection_price_type'] .
                '-' . $selectionData['selection_price_value'] .
                '-' . $selectionData['selection_qty'].
                '-' . $selectionData['selection_can_change_qty'];
        }
        foreach ($titleInfo as $titleData) {
            $productId = $option2product[$titleData['option_id']];
            $productInfo[$productId] .= '*' . $titleData['title'];
        }

        // compare the stored data with the new data to determine which products have changed

        $changed = [];

        foreach ($products as $product) {

            if (($options = $product->getOptions()) !== null) {

                $serialized = '';
                foreach ($options as $option) {
                    $serialized .= '*' . (int)$option->isRequired() . '-' . $option->getInputType();
                }
                foreach ($options as $option) {
                    foreach ($option->getSelections() as $selection) {
                        $serialized .= '*' . $selection->getProductId() .
                            '-' . (int)$selection->isDefault() .
                            '-' . $selection->getPriceType() .
                            '-' . $selection->getPriceValue() .
                            '-' . $selection->getQuantity() . '-' .
                            (int)$selection->isCanChangeQuantity();
                    }
                }
                foreach ($product->getStoreViews() as $storeView) {
                    foreach ($storeView->getOptionInformations() as $optionInformation) {
                        $serialized .= '*' . $optionInformation->getTitle();
                    }
                }

                if ($productInfo[$product->id] !== $serialized) {
                    $changed[] = $product;
                }
            }
        }

        return $changed;
    }
}