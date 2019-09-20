<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Model\OfflineResolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalog\QueryInterface;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
use G4NReact\MsCatalogMagento2GraphQl\Model\AbstractResolver;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Categories tree field resolver, used for GraphQL request processing.
 */
class Categories extends AbstractResolver
{
    /**
     * Name of type in GraphQL
     */
    const CATEGORY_INTERFACE = 'CategoryInterface';

    /**
     * @var String
     */
    const CATEGORY_OBJECT_TYPE = 'category';

    const DEPTH_DEFAULT = 5;

    /**
     * @param Field $field
     * @param $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     *
     * @return array|Document
     * @throws GraphQlInputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $result = [];
        $queryFields = $this->parseQueryFields($info);
        $depth = $args['depth'] ?? self::DEPTH_DEFAULT;
        $debug = isset($args['debug']) && $args['debug'];

        $result = $this->getCategories($depth, $queryFields, $debug);

        if (!empty($result)) {

            $items = (isset($result['categories']) && $result['categories']) ? $result['categories'] : [];
            $debugInfo = $result['debug_info'] ?? [];

            if ($items || $debugInfo) {
                $result = [
                    'items' => $items,
                    'debug_info' => $debugInfo,
                ];
            }

            $resultObject = new DataObject(['result' => $result]);
            $this->eventManager->dispatch(self::CATEGORY_OBJECT_TYPE . '_resolver_result_return_before', ['result' => $resultObject]);

            return $resultObject->getData('result');
        }

        return [];
    }

    /**
     * @param ResolveInfo $info
     *
     * @return array
     */
    public function parseQueryFields(ResolveInfo $info)
    {
        $queryFields = $info->getFieldSelection(3)['items'] ?? [];
        foreach ($queryFields as $name => $value) {
            if (is_array($value)) {
                unset($queryFields[$name]);
                continue;
            }
        }

        return $queryFields;
    }

    /**
     * @param array $categoryIds
     * @param null $levels
     * @param bool $children
     * @param array $queryFields
     *
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getCategories($depth = self::DEPTH_DEFAULT, $queryFields, $debug = false)
    {
        $categories = [];
        $searchEngineConfig = $this->configHelper->getConfiguration();
        $searchEngineClient = ClientFactory::create($searchEngineConfig);
        $categoryQuery = $searchEngineClient->getQuery();
        $this->addBaseFilters($categoryQuery);
        $categoryQuery->setPageStart(0);
        $categoryQuery->setPageSize(99999);

        $fieldsToSelect = [];
        foreach ($queryFields as $attributeCode => $value) {
            $fieldsToSelect[] = $this->queryHelper->getFieldByCategoryAttributeCode($attributeCode);
        }

        $categoryQuery->addFieldsToSelect($fieldsToSelect);

        $categoryQuery->addFilters([
            [
                $this->queryHelper->getFieldByCategoryAttributeCode(
                    'level',
                    new Document\FieldValue (null, '*', (int)$depth)
                )
            ],
        ]);

        $categoryQuery->setSorts([
            $this->queryHelper
                ->getFieldByCategoryAttributeCode('level', 'ASC'),
            $this->queryHelper
                ->getFieldByCategoryAttributeCode('position', 'ASC'),
        ]);

        $categoryResult = $categoryQuery->getResponse();
        $debugInfo = [];
        if ($debug) {
            $debugQuery = $categoryResult->getDebugInfo();
            $debugInfo = $debugQuery['params'] ?? [];
            $debugInfo['code'] = $debugQuery['code'] ?? 0;
            $debugInfo['message'] = $debugQuery['message'] ?? '';
            $debugInfo['uri'] = $debugQuery['uri'] ?? '';
        }

        if ($categoryResult->getNumFound()) {
            foreach ($categoryResult->getDocumentsCollection() as $category) {
                $solrCategory = $this->prepareDocumentResult($category, $queryFields, 'mscategory');
                $categories[] = $solrCategory;
            }
        }

        return [
            'categories' => $categories,
            'debug_info' => $debugInfo,
        ];
    }

    /**
     * @param QueryInterface $query
     */
    protected function addBaseFilters(QueryInterface $query)
    {
        $storeId = $this->storeManager->getStore()->getId();
        $query->addFilters([
            [
                $this->queryHelper->getFieldByCategoryAttributeCode('store_id', (int)$storeId)
            ],
            [
                $this->queryHelper->getFieldByCategoryAttributeCode('object_type', 'category')
            ],
        ]);

    }

}
