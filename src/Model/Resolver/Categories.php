<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\Query as QueryHelper;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Categories tree field resolver, used for GraphQL request processing.
 */
class Categories implements ResolverInterface
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
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var QueryHelper
     */
    protected $queryHelper;

    /**
     * @var EventManager
     */
    protected $eventManager;

    /**
     * Categories constructor
     *
     * @param DeploymentConfig $deploymentConfig
     * @param StoreManagerInterface $storeManager
     * @param ConfigHelper $configHelper
     * @param QueryHelper $queryHelper
     * @param EventManager $eventManager
     */
    public function __construct(
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        ConfigHelper $configHelper,
        QueryHelper $queryHelper,
        EventManager $eventManager
    ) {
        $this->deploymentConfig = $deploymentConfig;
        $this->storeManager = $storeManager;
        $this->configHelper = $configHelper;
        $this->queryHelper = $queryHelper;
        $this->eventManager = $eventManager;
    }

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
     * @inheritdoc
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
     */
    public function getCategoryFromSearchEngine(array $ids = [], $level = null, $children = false, $queryFields = [])
    {
        $categoryIds = implode(',', $ids);
        $categories = [];
        $storeId = $this->storeManager->getStore()->getId();
        $config = $this->configHelper->getConfiguration();
        $client = ClientFactory::create($config);
        $msCatalogForCategory = $client->getQuery();
        $msCatalogForCategory->setPageStart(0);

        $msCatalogForCategory->addFilters([
            [
                $this->queryHelper->getFieldByAttributeCode(
                        'store_id', $storeId, 'catalog_category'
                )
            ],
            [
                $this->queryHelper->getFieldByAttributeCode(
                    'object_type', 'category', 'catalog_category'
                )
            ],
        ]);

        if ($level) {
            $msCatalogForCategory->addFilter($this->queryHelper
                ->getFieldByAttributeCode('level', $level, 'catalog_category'));
            $msCatalogForCategory->setPageSize(1000);
            $fieldsToSelect = [];
            foreach ($queryFields as $attributeCode => $value) {
                $fieldsToSelect[] = $this->queryHelper->getFieldByAttributeCode($attributeCode, null, 'catalog_category');
            }
            $msCatalogForCategory->addFieldsToSelect($fieldsToSelect);
        } elseif ($children) {
            $msCatalogForCategory->addFilter($this->queryHelper
                ->getFieldByAttributeCode('parent_id', $categoryIds, 'catalog_category'));
            $msCatalogForCategory->setPageSize(100);
        } elseif ($categoryIds) {
            $msCatalogForCategory->addFilter($this->queryHelper
                ->getFieldByAttributeCode('id', $categoryIds, 'catalog_category'));
            $msCatalogForCategory->setPageSize(count($ids));
        }

        $msCatalogForCategory->setSort([
            [$this->queryHelper
                ->getFieldByAttributeCode('level', $categoryIds, 'catalog_category'), 'ASC'],
            [$this->queryHelper
                ->getFieldByAttributeCode('position', $categoryIds, 'catalog_category'), 'ASC'],
        ]);

        $categoryResult = $msCatalogForCategory->getResponse();

        if ($categoryResult->getNumFound()) {
            foreach ($categoryResult->getDocumentsCollection() as $category) {
                $solrCategory = $this->prepareCategoryResult($category, $queryFields);
                if ($level) {
                    if ($solrCategory['parent_id'] > 2) {
                        $categories[$solrCategory['parent_id']]['children'][$solrCategory['id']] = $solrCategory;
                    } else {
                        if (isset($categories[$solrCategory['id']])) {
                            $categories[$solrCategory['id']] = array_merge($solrCategory, $categories[$solrCategory['id']]);
                        } else {
                            $categories[$solrCategory['id']] = $solrCategory;
                        }
                    }
                } elseif ($children) {
                    if ($parentCategoryId = $solrCategory['parent_id']) {
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

    /**
     * @param Document $categoryData
     * @param array $queryFields
     * @return array
     */
    public function prepareCategoryResult(Document $categoryData, array $queryFields = [])
    {
        $this->eventManager->dispatch('prepare_mscategory_resolver_result_before', ['categoryData' => $categoryData]);

        if (empty($categoryData)) {
            return [];
        }

        $data = [];
        foreach ($queryFields as $fieldName => $value) {
            $data[$fieldName] = $this->parseToString($categoryData->getFieldValue($fieldName));
        }

        $this->eventManager->dispatch('prepare_mscategory_resolver_result_after', ['categoryData' => $categoryData]);

        return $data;
    }

    /**
     * @param $url
     * @return string
     */
    public function parseUrl($url)
    {
        if ($url) {
            return '/' . ltrim($this->parseToString($url), '/');
        }

        return '';
    }

    /**
     * @param $field
     * @return string
     */
    public function parseToString($field)
    {
        return is_array($field) ? implode(', ', $field) : $field;
    }
}
