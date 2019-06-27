<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalog\Query;
use G4NReact\MsCatalogSolr\Response;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
use Global4net\CatalogGraphQl\Model\Resolver\DataProvider\Attribute;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Products
 * @package Global4net\CatalogGraphQl\Model\Resolver
 */
class Products implements ResolverInterface
{
    /**
     * CatalogGraphQl products cache key
     */
    const CACHE_KEY_CATEGORY = 'G4N_CAT_GRAPH_QL_PROD';

    /**
     * @var string
     */
    const CACHE_KEY_SEARCH = 'G4N_SEARCH_PROD';

    /**
     * @var String
     */
    const PRODUCT_OBJECT_TYPE = 'product';

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Attribute
     */
    private $attributesDefinition;

    /**
     * @var Json
     */
    protected $serializer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    public static $idTypeMapping = [
        'ID'  => 'object_id',
        'SKU' => 'sku',
    ];

    /**
     * @var array
     */
    public static $attributeMapping = [
        'category_id'       => 'category',
        'sku'               => 'sku_s',
        'id'                => 'object_id',
        'price'             => 'price_f',
        'max_sale_qty'      => 'max_sale_qty_i',
        'min_sale_qty'      => 'min_sale_qty_i'
    ];

    /**
     * @var array
     */
    public static $sortMapping = [
        'score'        => 'score',
        'name'         => 'name_s',
        'price'        => 'price_f',
    ];

    /**
     * @var array
     */
    public static $defaultAttributes = [
        'category',
        'price'
    ];

    /**
     * @var string
     */
    public $resolveInfo;

    /**
     * Products constructor.
     * @param CacheInterface $cache
     * @param DeploymentConfig $deploymentConfig
     * @param StoreManagerInterface $storeManager
     * @param Store $magentoOneHelper
     * @param MagentoOneConnection $magentoOneConnection
     * @param Attribute $attributesDefinition
     * @param Json $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        CacheInterface $cache,
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        Attribute $attributesDefinition,
        Json $serializer,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->deploymentConfig = $deploymentConfig;
        $this->storeManager = $storeManager;
        $this->attributesDefinition = $attributesDefinition;
        $this->serializer = $serializer;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!isset($args['search']) && !isset($args['filter'])) {
            throw new GraphQlInputException(
                __("'search' or 'filter' input argument is required.")
            );
        }

        $this->resolveInfo = $info->getFieldSelection(3);
        // venia outside of variables, he asks for __typename // Venia is "he"? I thought it is "she" :)
        $limit = (isset($this->resolveInfo['items']) && isset($this->resolveInfo['items']['__typename'])) ? 2 : 1;
        if ((isset($this->resolveInfo['items']) && count($this->resolveInfo['items']) <= $limit && isset($this->resolveInfo['items']['sku']))
            || (isset($this->resolveInfo['items_ids']))
        ) {
            $defaultPageSize = 10000;
            $args['fields_to_fetch'] = [self::$attributeMapping['sku']];
            $args['id_type'] = self::$idTypeMapping['SKU'];
            if (isset($args['filter']) && isset($args['filter']['id_type']) && ($idType = $args['filter']['id_type'])) {
                $args['fields_to_fetch'] = [self::$attributeMapping[strtolower($idType)]];
                $args['id_type'] = self::$idTypeMapping[$idType];
            }
        } elseif (isset($args['search']) && $args['search']) {
            $defaultPageSize = 3000;
        } else {
            $defaultPageSize = 100;
        }
        $args['pageSize'] = $args['pageSize'] ?? $defaultPageSize;

        $fields = [];
        $additional = '';

        $storeId = $this->storeManager->getStore()->getId();

        if (isset($args['search']) && $args['search']) {
            $searchText = Parser::parseSearchText($args['search']);
            $searchText = trim(str_replace('-', ' ', $searchText));
//            $searchText = Parser::escape(str_replace('\\', '', PhpExt::alphanumAdvanced($searchText)));
            $searchText = Parser::escape(str_replace('\\', '', $searchText));
//            $searchText = PhpExt::convertPolishLetters($searchText);
            $searchText = Parser::parseIsInt($searchText);
            $args['search'] = $searchText;

            if (isset($args['filter'])) {
                $args['filter'] = $this->getFilters($args['filter']);
            }
            if (!isset($args['sort'])) {
                $args['sort'] = ['sort_by' => 'score', 'sort_order' => 'desc'];
            }
        } elseif (isset($args['filter'])) {
            $args['filter'] = $this->getFilters($args['filter']);
            if (!isset($args['sort'])) {
                $args['sort'] = ['sort_by' => 'popularity', 'sort_order' => 'desc'];
            }
        }

//        $facetAndStats = null;
//        if ((isset($args['facet']) && $args['facet'])) {
//            $facetAndStats = $this->prepareFacetsAndStats();
//
//            if ($facetAndStats) {
//                if (isset($facetAndStats['facet'])) {
//                    $args['facet'] = $facetAndStats['facet'];
//                }
//                if (isset($facetAndStats['stat'])) {
//                    $args['stat'] = $facetAndStats['stat'];
//                }
//            }
//        }
//        if (!$facetAndStats) {
//            unset($args['facet']);
//        }

        $args['filter_query'] = [
            'store_id'     => $storeId,
            'object_type' => 'product'
        ];

        return $this->getDataFromSolr($args, $fields, $additional);
    }

    /**
     * @param $options
     * @param $searchFields
     * @param $additional
     * @return array
     */
    public function getDataFromSolr($options, $searchFields, $additional)
    {
        $config = $this->deploymentConfig->getConfigData('old_solr');

        $msCatalog = new Query('solr', $config, $options);
        $msCatalog->setSort($this->getSortParam($options['sort']));
        $msCatalog->setSearchFields($searchFields);

        if ($additional) {
            $msCatalog->setSearchAdditionalInfo($additional);
        }

        $result = $msCatalog->getResult();
        $resultFacets = $this->parseAttributeCode($result->getFacets());
        $resultStats = $this->parseAttributeCode($result->getStats());

        if (!empty($activeAttributesCode)) {
            foreach ($activeAttributesCode as $attributeCode) {
                $optionsForFilter = $options;
                foreach ($optionsForFilter['filter'] as $key => $optionFilter) {
                    if (is_array($optionFilter)) {
                        if (isset($optionFilter[$attributeCode])) {
                            unset($optionsForFilter['filter'][$key][$attributeCode]);
                        }
                    } else {
                        if ($key = $attributeCode) {
                            unset($optionsForFilter['filter'][$attributeCode]);
                        }
                    }
                }

                $optionsForFilter['pageSize'] = 0;
                $optionsForFilter['currentPage'] = 0;
                $optionsForFilter['fields_to_fetch'][] = 'id';
                $msCatalogParent = new Query('solr', $config, $optionsForFilter);
                $msCatalogParent->setSearchFields($searchFields);

                if ($additional) {
                    $msCatalogParent->setSearchAdditionalInfo($additional);
                }

                $parentResult = $msCatalogParent->getResult();
                $parentFacets = $this->parseAttributeCode($parentResult->getFacets());
                $parentStats = $this->parseAttributeCode($parentResult->getStats());

                if (isset($parentFacets[$attributeCode])) {
                    $resultFacets[$attributeCode] = $parentFacets[$attributeCode];
                }
                if ($attributeCode == 'price' && ($values = $parentStats[$attributeCode]['values'] ?? null)) {
                    $resultStats[$attributeCode]['values'] = $values;
                }
            }
        }

        $result->setFacets($resultFacets);
        $result->setStats($resultStats);

        return $this->prepareResultData($result, $options['id_type'] ?? '', $options['search'] ?? false);
    }

    /**
     * @param Response $result
     * @param $idType
     * @param $forSearch
     * @return array
     * @throws NoSuchEntityException
     */
    public function prepareResultData($result, $idType, $forSearch)
    {
        $products = $this->getProducts($result->getDocumentsCollection(), $idType, $forSearch);

        $data = [
            'total_count' => $result->getNumFound(),
            'items_ids'   => $products['items_ids'],
            'items'       => $products['items'],
            'page_info'   => [
                'page_size'    => count($result->getDocumentsCollection()),
                'current_page' => $result->getCurrentPage(),
                'total_pages'  => $result->getNumFound()
            ],
            'facets'      => $result->getFacets(),
            'stats'       => $result->getStats()
        ];

        return $data;
    }

    /**
     * @param $filters
     * @param $mapping
     * @return array
     * @throws NoSuchEntityException
     */
    public function getFilters($filters, $mapping = true)
    {
        $resultParams = [];
        if ($mapping) {
            $params = $this->parameterMapping($filters);
        } else {
            $params = $filters;
        }

        foreach ($params as $param) {
            if (is_array($param)) {
                array_push($resultParams, $this->getFilters($param, $mapping));
                continue;
            }
            $codeAndValue = explode('=', $param);
            $attributeCode = $codeAndValue[0];
            if (isset($codeAndValue[1]) && ($codeAndValue[1] || in_array($attributeCode, self::$attributeFlagsMapping))) {
                $attributeValue = $codeAndValue[1];
                if (in_array($attributeCode, self::$attributeMapping) || in_array($attributeCode, self::$attributeFlagsMapping)) {
                    $resultParams[] = $param;
                    continue;
                }
                if ($solrAttributeCode = $this->getSolrAttributeCode($attributeCode)) {
                    $resultParams[$attributeCode] = $solrAttributeCode . '=' . $attributeValue;
                }
            }
        }

        return $resultParams;
    }

    /**
     * @param array $solrAttributes
     * @return array
     */
    public function parseAttributeCode($solrAttributes = [])
    {
        $newSolrAttributes = [];
        foreach ($solrAttributes as $key => $attribute) {
            $attributeCode = str_replace(['_facet', '_f'], ['', ''], $attribute['code']);
            $newSolrAttributes[$attributeCode]['code'] = $attributeCode;
            $newSolrAttributes[$attributeCode]['values'] = $attribute['values'];
        }

        return $newSolrAttributes;
    }

    /**
     * @param $filters
     * @return array
     */
    public function parameterMapping($filters)
    {
        $params = [];

        foreach ($filters as $key => $filter) {
            switch ($key) {
                case 'id_type':
                    break;
                case in_array($key, array_keys(self::$attributeMapping)):
                    $preparedFilter = $this->prepareFilter($filter, self::$attributeMapping[$key]);
                    if ($key == 'sku') {
                        $preparedFilter = str_replace('-', '\-', $preparedFilter);
                    }
                    $params[] = $preparedFilter;
                    break;
                case 'ids':
                    if ($filterValue = Parser::parseFilterValueNumeric(implode(',', array_filter($filter)))) {
                        $params[] = 'object_id=' . preg_replace('/[,]{2,}/i', ',', rtrim($filterValue, ','));
                    }
                    break;
                case 'skus':
                    if ($filterValue = Parser::parseFilterValueNumeric(implode(',', array_filter($filter)))) {
                        $skus = 'sku_i=' . preg_replace('/[,]{2,}/i', ',', rtrim($filterValue, ','));
                        $params[] = str_replace('-', '\-', $skus);
                    }
                    break;
                case 'custom':
                    $customFilters = [];
                    foreach ($filter as $customFilter) {
                        if ($code = Parser::parseFilter($customFilter['code'])) {
                            $customFilters[$code][] = $this->prepareFilter($customFilter['input']);
                        }
                    }

                    foreach ($customFilters as $code => $values) {
                        if ($filterValue = Parser::parseFilterValue(implode(',', $values))) {
                            $params[] = $code . '=' . $filterValue;
                        }
                    }
                    break;
                case 'attributes':
                    $params = array_merge($params, Parser::parseFilters($filter));
                    break;
                default:
                    if ($key = Parser::parseFilter($key)) {
                        $params[] = $this->prepareFilter($filter, $key);
                    }
            }
        }

        return $params;
    }

    /**
     * @param $filter
     * @param null $code
     * @return string
     */
    public function prepareFilter($filter, $code = null)
    {
        $queryFilter = [];

        foreach ($filter as $op => $value) {
            $value = Parser::parseFilter($value);
            if ($value && $op == 'eq') {
                $queryFilter[] = $value;
            }
        }

        if ($code) {
            return $code . '=' . implode(',', $queryFilter);
        }
        return implode(',', $queryFilter);
    }

    /**
     * @param $sort
     * @param $categoryId
     * @return array
     */
    private function getSortParam($sort)
    {
        $sort = Parser::parseFilters($sort);
        $currentSortField = isset($sort['sort_by']) ? $sort['sort_by'] : 'popularity';
        $currentSortDirection = isset($sort['sort_order']) ? $sort['sort_order'] : 'DESC';

        if (isset(self::$sortMapping[$currentSortField])) {
            $currentSortField = self::$sortMapping[$currentSortField];
            if (!in_array($currentSortDirection, ['ASC', 'DESC'])) {
                $currentSortDirection = 'DESC';
            }
        } else {
            $currentSortField = self::$sortMapping['popularity'];
            $currentSortDirection = 'DESC';
        }

        $sortBy[] = [$currentSortField => $currentSortDirection];

        return $sortBy;
    }

    /**
     * @param $documentCollection
     * @param $idType
     * @return array
     * @throws NoSuchEntityException
     */
    public function getProducts($documentCollection, $idType)
    {
        $products = [];
        $productIds = [];

        $i = 300; // default for product order in search

        /** @var Document $document */
        foreach ($documentCollection as $document) {
            if ($idType) {
                $productIds[$i] = $this->parseToString($document->getFieldValue($idType));
            }

            $url = parse_url($document->getFieldValue('url') ?: '');
            $productData = [
                'id'                               => $this->parseToString($document->getFieldValue('object_id')),
                'sku'                              => $this->parseToString($document->getFieldValue('sku')),
                'name'                             => $this->parseToString($document->getFieldValue('name')),
                'description'                      => $this->parseToString($document->getFieldValue('description')),
//                'price'                            => $this->parseToString($document->getFieldValue('original_price')),
                'final_price'                      => $this->parseToString($document->getFieldValue('price')),
//                'has_special_price'                => $this->parseToString($document->getFieldValue('has_special_price')),
                'type_id'                          => $this->parseToString($document->getFieldValue('type_id')),
                'url'                              => $url['path'] ?? '',
                'object_type'                      => self::PRODUCT_OBJECT_TYPE,
            ];

            $products[$i] = $productData;
            $i++;
        }

        ksort($productIds);
        ksort($products);

        return ['items' => $products, 'items_ids' => $productIds];
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
