<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalog\QueryInterface;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
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

    /**
     * Get category id
     *
     * @param array $args
     *
     * @return array
     */
    private function getCategoryIds(array $args): array
    {
        $ids = (isset($args['ids']) && (count($args['ids']) > 0)) ? $args['ids'] : [];
        return Parser::parseArrayIsInt($ids);
    }

    /**
     * @param array $args
     *
     * @return array
     */
    private function getLevel(array $args): array
    {
        $levels = $args['levels'] ?? [];
        return array_map('intval', $levels);
    }

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
        $resolveObject = new DataObject([
            'field' => $field,
            'context' => $context,
            'resolve_info' => $info,
            'value' => $value,
            'args' => $args
        ]);
        $this->eventManager->dispatch(
            self::CATEGORY_OBJECT_TYPE . '_resolver_resolve_before',
            ['resolve' => $resolveObject]
        );

        if (empty($args)) {
            throw new GraphQlInputException(__('"id or level for category should be specified'));
        }

        $result = [];
        $queryFields = $this->parseQueryFields($info);
        $categoryIds = $this->getCategoryIds($args);
        $levels = $this->getLevel($args);
        $children = isset($args['children']) ? true : false;
        $debug = isset($args['debug']) && $args['debug'];

        if ($children) {
            $childrenQueryFields = $info->getFieldSelection(3)['items']['children'] ?? [];
            $queryFields = array_merge($queryFields, $childrenQueryFields);
        }

        if ($levels || count($categoryIds)) {
            $result = $this->getCategoryFromSearchEngine($categoryIds, $levels, $children, $queryFields, $debug);
        }

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

        return new Document();
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
    public function getCategoryFromSearchEngine(array $categoryIds = [], $levels = null, $children = false, $queryFields = [], $debug = false)
    {
        $categories = [];
        $searchEngineConfig = $this->configHelper->getConfiguration();
        $searchEngineClient = ClientFactory::create($searchEngineConfig);
        $msCatalogForCategory = $searchEngineClient->getQuery();
        $msCatalogForCategory->setPageStart(0);

        $this->handleFilters($msCatalogForCategory, $queryFields);

        $fieldsToSelect = [];
        foreach ($queryFields as $attributeCode => $value) {
            $fieldsToSelect[] = $this->queryHelper->getFieldByCategoryAttributeCode($attributeCode);
        }
        if ($levels) {
            $msCatalogForCategory->addFilters([
                [
                    $this->queryHelper->getFieldByCategoryAttributeCode('level', $levels)
                ],
            ]);
            $msCatalogForCategory->setPageSize($this->getMaxPageSize());
            $fieldsToSelect[] = $this->queryHelper->getFieldByCategoryAttributeCode('level');
            $queryFields['level'] = 1;
            $fieldsToSelect[] = $this->queryHelper->getFieldByCategoryAttributeCode('parent_id');
            $queryFields['parent_id'] = 1;
        } elseif ($children) {
            $msCatalogForCategory->addFilter($this->queryHelper
                ->getFieldByCategoryAttributeCode('parent_id', $categoryIds));
            $msCatalogForCategory->setPageSize($this->getMaxPageSize());
            $fieldsToSelect[] = $this->queryHelper->getFieldByCategoryAttributeCode('parent_id');
            $queryFields['parent_id'] = 1;
        } elseif ($categoryIds) {
            $msCatalogForCategory->addFilter($this->queryHelper
                ->getFieldByCategoryAttributeCode('id', $categoryIds));
            $msCatalogForCategory->setPageSize(count($categoryIds));
        }

        $msCatalogForCategory->addFieldsToSelect($fieldsToSelect);

        $msCatalogForCategory->setSorts([
            $this->queryHelper
                ->getFieldByCategoryAttributeCode('level', 'ASC'),
            $this->queryHelper
                ->getFieldByCategoryAttributeCode('position', 'ASC'),
        ]);

        $categoryResult = $msCatalogForCategory->getResponse();
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
                if ($levels) {
                    if (isset($solrCategory['parent_id']) && ($solrCategory['parent_id'] > 2)) {
                        if (!isset($categories[$solrCategory['parent_id']])) {
                            continue;
                        }
                        $categories[$solrCategory['parent_id']]['children'][$solrCategory['id']] = $solrCategory;
                    } else {
                        if (isset($categories[$solrCategory['id']])) {
                            $categories[$solrCategory['id']] = array_merge($solrCategory, $categories[$solrCategory['id']]);
                        } else {
                            $categories[$solrCategory['id']] = $solrCategory;
                        }
                    }
                } elseif ($children) {
                    if (isset($solrCategory['parent_id'])) {
                        $parentCategoryId = $solrCategory['parent_id'];
                        if (isset($categories[$parentCategoryId])) {
                            $categories[$parentCategoryId][] = $solrCategory;
                        } else {
                            $categories[$parentCategoryId] = [$solrCategory];
                        }
                    }
                } else {
                    $categories[] = $solrCategory;
                }
            }
        }

        if ($children) {
            $catIds = [];
            foreach ($categoryIds as $cat) {
                $catIds[] = $cat;
            }

            $parentCategories = $categories;
            $categories = [];
            if (!$levels) {
                $msCatalogForCategory->addFilter($this->queryHelper
                    ->getFieldByCategoryAttributeCode('id', $categoryIds), false, 'OR');
                $categoryResult2 = $msCatalogForCategory->getResponse();
                $parents = [];
                foreach ($categoryResult2->getDocumentsCollection() as $category) {
                    $solrCategory = $this->prepareDocumentResult($category, $queryFields, 'mscategory');
                    if (in_array($solrCategory['id'], $catIds)) {
                        $parents[] = $solrCategory;
                    }
                }
                foreach ($parentCategories as $id => $children) {
                    foreach ($parents as $parent) {
                        if ($parent['id'] == $id) {
                            $parent['children'] = $children;
                            $categories[] = $parent;
                        }
                    }
                }
            } else {
                foreach ($parentCategories as $id => $children) {
                    $categories[] = [
                        'id' => $id,
                        'children' => $children
                    ];
                }
            }
        }

        return [
            'categories' => $categories,
            'debug_info' => $debugInfo,
        ];
    }

    /**
     * @param QueryInterface $query
     * @param array $args
     */
    protected function handleFilters(QueryInterface $query, array $args)
    {
        $storeId = $this->storeManager->getStore()->getId();
        
        $query->addFilters([
            [
                $this->queryHelper->getFieldByCategoryAttributeCode('store_id', $storeId)
            ],
            [
                $this->queryHelper->getFieldByCategoryAttributeCode('object_type', 'category')
            ],
        ]);

        if (isset($args['filter']) && is_array($args['filter']) && ($filters = $this->prepareFiltersByArgsFilter($args['filter'], 'category'))) {
            $query->addFilters($filters);
        }
    }

    /**
     * @todo rethink it, maybe put max page size in configuration or sth
     * @return int
     */
    public function getMaxPageSize()
    {
        return 2000;
    }
}
