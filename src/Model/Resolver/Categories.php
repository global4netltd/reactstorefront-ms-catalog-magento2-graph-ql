<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
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
     * @return array
     */
    private function getCategoryIds(array $args): array
    {
        $ids = (isset($args['ids']) && (count($args['ids']) > 0)) ? $args['ids'] : [];
        return Parser::parseArrayIsInt($ids);
    }

    /**
     * @param array $args
     * @return array
     */
    private function getLevel(array $args): array
    {
        $levels = $args['level'] ?? [];
        return array_map('intval', $levels);
    }

    /**
     * @param Field $field
     * @param $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array|Document
     * @throws GraphQlInputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (empty($args)) {
            throw new GraphQlInputException(__('"id or level for category should be specified'));
        }

        $categories = [];
        $queryFields = $this->parseQueryFields($info);
        $categoryIds = $this->getCategoryIds($args);
        $level = $this->getLevel($args);
        if ($level || count($categoryIds)) {
            $categories = $this->getCategoryFromSearchEngine($categoryIds, $level, isset($args['children']) ? true : false, $queryFields);
        }

        if (!empty($categories)) {
            return ['items' => $categories];
        }

        return new Document();
    }

    /**
     * @param ResolveInfo $info
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
     * @param array $ids
     * @param null $level
     * @param bool $children
     * @param array $queryFields
     * @return array
     * @throws NoSuchEntityException
     * @throws LocalizedException
     * @throws Exception
     */
    public function getCategoryFromSearchEngine(array $ids = [], $level = null, $children = false, $queryFields = [])
    {
        $categoryIds = implode(',', $ids);
        $categories = [];
        $storeId = $this->storeManager->getStore()->getId();
        $searchEngineConfig = $this->configHelper->getConfiguration();
        $searchEngineClient = ClientFactory::create($searchEngineConfig);
        $msCatalogForCategory = $searchEngineClient->getQuery();
        $msCatalogForCategory->setPageStart(0);

        $msCatalogForCategory->addFilters([
            [
                $this->queryHelper->getFieldByCategoryAttributeCode('store_id', $storeId)
            ],
            [
                $this->queryHelper->getFieldByCategoryAttributeCode('object_type', 'category')
            ],
        ]);

        $fieldsToSelect = [];
        foreach ($queryFields as $attributeCode => $value) {
            $fieldsToSelect[] = $this->queryHelper->getFieldByCategoryAttributeCode($attributeCode);
        }

        if ($level) {
            $msCatalogForCategory->addFilter($this->queryHelper
                ->getFieldByCategoryAttributeCode('level', $level));
            $msCatalogForCategory->setPageSize(1000);
            $fieldsToSelect[] = $this->queryHelper->getFieldByCategoryAttributeCode('level');
            $queryFields['level'] = 1;
            $fieldsToSelect[] = $this->queryHelper->getFieldByCategoryAttributeCode('parent_id');
            $queryFields['parent_id'] = 1;
        } elseif ($children) {
            $msCatalogForCategory->addFilter($this->queryHelper
                ->getFieldByCategoryAttributeCode('parent_id', $categoryIds));
            $msCatalogForCategory->setPageSize(100);
            $fieldsToSelect[] = $this->queryHelper->getFieldByCategoryAttributeCode('parent_id');
            $queryFields['parent_id'] = 1;
        } elseif ($categoryIds) {
            $msCatalogForCategory->addFilter($this->queryHelper
                ->getFieldByCategoryAttributeCode('id', $categoryIds));
            $msCatalogForCategory->setPageSize(count($ids));
        }

        $msCatalogForCategory->addFieldsToSelect($fieldsToSelect);

        $msCatalogForCategory->setSort([
            [$this->queryHelper
                ->getFieldByCategoryAttributeCode('level', $categoryIds), 'ASC'],
            [$this->queryHelper
                ->getFieldByCategoryAttributeCode('position', $categoryIds), 'ASC'],
        ]);

        $categoryResult = $msCatalogForCategory->getResponse();

        if ($categoryResult->getNumFound()) {
            foreach ($categoryResult->getDocumentsCollection() as $category) {
                $solrCategory = $this->prepareDocumentResult($category, $queryFields, 'mscategory');
                if ($level) {
                    if (isset($solrCategory['parent_id']) && ($solrCategory['parent_id'] > 2)) {
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
            $parentCategories = $categories;
            $categories = [];
            foreach ($parentCategories as $id => $children) {
                $categories[] = [
                    'id'       => $id,
                    'children' => $children
                ];
            }
        }

        return $categories;
    }
}
