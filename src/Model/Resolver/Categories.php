<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalog\Query;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\Query as QueryHelper;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
use Magento\Framework\App\DeploymentConfig;
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
    protected $msCatalogMagento2Helper;

    /**
     * @var QueryHelper
     */
    protected $msCatalogQueryHelper;

    /**
     * @var array
     */
    public static $attributeMapping = [
        'level'     => 'level_s_ni',
        'url'       => 'url_s_ni',
        'name'      => 'name_s',
        'position'  => 'position_s_ni',
        'parent_id' => 'parent_id_s',
        'store_id'  => 'store_id_s',
    ];

    /**
     * Categories constructor.
     * @param DeploymentConfig $deploymentConfig
     * @param StoreManagerInterface $storeManager
     * @param ConfigHelper $msCatalogMagento2Helper
     * @param QueryHelper $msCatalogQueryHelper
     */
    public function __construct(
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        ConfigHelper $msCatalogMagento2Helper,
        QueryHelper $msCatalogQueryHelper
    ) {
        $this->deploymentConfig = $deploymentConfig;
        $this->storeManager = $storeManager;
        $this->msCatalogMagento2Helper = $msCatalogMagento2Helper;
        $this->msCatalogQueryHelper = $msCatalogQueryHelper;
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

        $solrCategories = [];
        $categoryIds = $this->getCategoryIds($args);
        $level = $this->getLevel($args);
        if ($level || count($categoryIds)) {
            $solrCategories = $this->getCategoryFromSolr($categoryIds, $level, isset($args['children']) ? true : false);
        }

        if (!empty($solrCategories)) {
            return ['items' => $solrCategories];
        }

        return new Document();
    }

    /**
     * @param array $ids
     * @param null $level
     * @param bool $children
     * @return array
     * @throws NoSuchEntityException
     * @throws Exception
     */
    public function getCategoryFromSolr(array $ids = [], $level = null, $children = false)
    {
        $categoryIds = implode(',', $ids);
        $categories = [];

        $storeId = $this->storeManager->getStore()->getId();

//        $params['filter_query'] = [
//            self::$attributeMapping['store_id'] => $storeId,
//            'object_type' => 'category',
//        ];
//        if ($level) {
//            $params['filter'] = [self::$attributeMapping['level'] . '=' . implode(',', $level)];
//            $params['pageSize'] = 1000;
//            $params['fields_to_fetch'] = self::$attributeMapping;
//            $params['fields_to_fetch'][] = ['object_id'];
//        } elseif ($children) {
//            $params['filter'] = [self::$attributeMapping['parent_id'] . '=' . $categoryIds];
//            $params['pageSize'] = 100;
//        } elseif ($categoryIds) {
//            $params['filter'] = ['object_id=' . $categoryIds];
//            $params['pageSize'] = count($ids);
//        }

        $config = $this->msCatalogMagento2Helper->getConfiguration();
        $client = ClientFactory::create($config);
        $msCatalogForCategory = $client->getQuery();

        $msCatalogForCategory->addFilters([
            [
                $this->msCatalogQueryHelper
                    ->getFieldByAttributeCode('store_id', 'catalog_category'),
                $storeId
            ],
            [
                $this->msCatalogQueryHelper
                    ->getFieldByAttributeCode('object_type', 'catalog_category'),
                'category'
            ],
        ]);

        if ($level) {
            $msCatalogForCategory->addFilter(
                $this->msCatalogQueryHelper
                    ->getFieldByAttributeCode('level', 'catalog_category'),
                $level
            );
            $msCatalogForCategory->setPageSize(1000);
            $msCatalogForCategory->addFieldsToSelect([
                'level', 'url', 'name', 'position', 'parent_id', 'store_id', 'object_id'
            ]);
        } elseif ($children) {
            $msCatalogForCategory->addFilter(
                $this->msCatalogQueryHelper
                    ->getFieldByAttributeCode('parent_id', 'catalog_category'),
                $categoryIds
            );
            $msCatalogForCategory->setPageSize(100);
        } elseif ($categoryIds) {
            $msCatalogForCategory->addFilter(
                $this->msCatalogQueryHelper
                    ->getFieldByAttributeCode('object_id', 'catalog_category'),
                $categoryIds
            );
            $msCatalogForCategory->setPageSize(count($ids));
        }

        $msCatalogForCategory->setSort([
            [self::$attributeMapping['level'], 'ASC'],
            [self::$attributeMapping['position'], 'ASC'],
        ]);

//        $msCatalogForCategory->setSort([[self::$attributeMapping['level'] => 'ASC'], [self::$attributeMapping['position'] => 'ASC']]);

        $categoryResult = $msCatalogForCategory->getResponse();

        if ($categoryResult->getNumFound()) {
            foreach ($categoryResult->getDocumentsCollection() as $category) {
                $solrCategory = $this->prepareCategoryResult($category);
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
     * @return array
     * @throws NoSuchEntityException
     */
    public function prepareCategoryResult(Document $categoryData)
    {
        if (empty($categoryData)) {
            return [];
        }

        $urlCategory = parse_url($categoryData->getFieldValue('url') ?: '');
        $urlImage = parse_url($categoryData->getFieldValue('image') ?: '');

        $data = [
            'id'               => $this->parseToString($categoryData->getFieldValue('object_id')),
            'name'             => $this->parseToString($categoryData->getFieldValue('name')),
            'description'      => $this->parseToString($categoryData->getFieldValue('description')),
            'meta_title'       => $this->parseToString($categoryData->getFieldValue('meta_title')),
            'meta_description' => $this->parseToString($categoryData->getFieldValue('meta_description')),
            'meta_keywords'    => $this->parseToString($categoryData->getFieldValue('meta_keywords')),
            'children_count'   => $this->parseToString($categoryData->getFieldValue('children_count')),
            'path'             => $this->parseToString($categoryData->getFieldValue('path')),
            'level'            => $this->parseToString($categoryData->getFieldValue('level')),
            'position'         => $this->parseToString($categoryData->getFieldValue('position')),
            'parent_id'        => $this->parseToString($categoryData->getFieldValue('parent_id')),
            'display_mode'     => $this->parseToString($categoryData->getFieldValue('display_mode')),
            'image'            => $urlImage['path'] ?? '',
            'url'              => $urlCategory['path'] ?? '',
            'url_key'          => $this->parseToString($categoryData->getFieldValue('url_key')),
            'url_path'         => $this->parseUrl($categoryData->getFieldValue('url_path')),
            'seo_robots'       => $this->parseToString($categoryData->getFieldValue('seo_robots')),
            'object_type'      => self::CATEGORY_OBJECT_TYPE
        ];

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
