<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalog\QueryInterface;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\Query;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Products
 * @package Global4net\CatalogGraphQl\Model\Resolver
 */
class Products extends AbstractResolver
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
     * @var array
     */
    public static $defaultAttributes = [
        'category',
        'price'
    ];

    /**
     * @var CategoryRepository
     */
    protected $categoryRepository;

    /**
     * List of attributes codes that we can skip when returning attributes for product
     *
     * @var array
     */
    public static $attributesToSkip = [];

    /**
     * @var string
     */
    public $resolveInfo;

    /**
     * AbstractResolver constructor.
     *
     * @param CacheInterface $cache
     * @param DeploymentConfig $deploymentConfig
     * @param StoreManagerInterface $storeManager
     * @param Json $serializer
     * @param LoggerInterface $logger
     * @param ConfigHelper $configHelper
     * @param Query $queryHelper
     * @param EventManager $eventManager
     * @param CategoryRepository $categoryRepository
     */
    public function __construct(
        CacheInterface $cache,
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        Json $serializer,
        LoggerInterface $logger,
        ConfigHelper $configHelper,
        Query $queryHelper,
        EventManager $eventManager,
        CategoryRepository $categoryRepository
    ) {
        $this->categoryRepository = $categoryRepository;

        return parent::__construct($cache, $deploymentConfig, $storeManager, $serializer, $logger, $configHelper, $queryHelper, $eventManager);
    }

    /**
     * @param Field $field
     * @param $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws Exception
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

        $searchEngineConfig = $this->configHelper->getConfiguration();
        $searchEngineClient = ClientFactory::create($searchEngineConfig);

        $query = $searchEngineClient->getQuery();
        $this->handleSort($query, $info);
        $this->handleFilters($query, $args);
        $this->handleFacets($query, $args);

        $this->resolveInfo = $info->getFieldSelection(3);

        // venia outside of variables, he asks for __typename
        $limit = (isset($this->resolveInfo['items']) && isset($this->resolveInfo['items']['__typename'])) ? 2 : 1;
        if ((isset($this->resolveInfo['items']) && count($this->resolveInfo['items']) <= $limit && isset($this->resolveInfo['items']['sku']))
            || (isset($this->resolveInfo['items_ids']))
        ) {
            $maxPageSize = 10000;

            $query->addFieldsToSelect([
                $this->queryHelper->getFieldByAttributeCode('sku'),
            ]);
        } else {
            $this->handleFieldsToSelect($query, $info);
            $maxPageSize = 3000;
        }

        $pageSize = (isset($args['pageSize']) && ($args['pageSize'] < $maxPageSize)) ? $args['pageSize'] : $maxPageSize;
        $query->setPageSize($pageSize);

        if (isset($args['search']) && $args['search']) {
            $searchText = Parser::parseSearchText($args['search']);
            $query->setQueryText($searchText);
        }

        $response = $query->getResponse();

        $products = $this->prepareResultData($response);

        return $products;
    }

    /**
     * @param QueryInterface $query
     * @param $args
     * @throws LocalizedException
     */
    public function handleFacets($query, $args)
    {
        /**
         * @todo only for testing purpopse, eventually handle facets from category,
         */
        $query->addFacet(
            $this->queryHelper->getFieldByAttributeCode('category_id')
        );
    }

    /**
     * @param QueryInterface $query
     * @param $info
     * @throws LocalizedException
     */
    public function handleSort($query, $info)
    {
        $sortDir = 'ASC';

        $sort = false;
        if (isset($args['sort']) && isset($args['sort']['sort_by'])) {
            $sort = $this->prepareSortField($args['sort']['sort_by']);
        } elseif (isset($args['search']) && $args['search']) {
            $sort = $this->prepareSortField('score');
            $sortDir = 'DESC';
        }

        if ($sort) {
            $query->addSort($sort, $sortDir);
        }
    }

    /**
     * @param QueryInterface $query
     * @param $info
     * @throws LocalizedException
     */
    public function handleFieldsToSelect($query, $info)
    {
        $queryFields = $this->parseQueryFields($info);

        $fieldsToSelect = [];
        foreach ($queryFields as $attributeCode => $value) {
            $fieldsToSelect[] = $this->queryHelper->getFieldByAttributeCode($attributeCode);
        }

        $query->addFieldsToSelect($fieldsToSelect);
    }

    /**
     * @param QueryInterface $query
     * @param $args
     * @throws LocalizedException
     */
    public function handleFilters($query, $args)
    {
//        if (isset($args['filter'])) {
//            $args['filter'] = $this->getFilters($args['filter']);
//        }

        $query->addFilters([
            [$this->queryHelper->getFieldByAttributeCode(
                'store_id',
                $this->storeManager->getStore()->getId(),
                'catalog_category'
            )],
            [$this->queryHelper->getFieldByAttributeCode(
                'object_type',
                'product',
                'catalog_category'
            )],
        ]);

        if (isset($args['filter']) && ($filters = $this->prepareFiltersByArgsFilter($args['filter']))) {
            $query->addFilters($filters);
        }
    }

    /**
     * @param $response
     * @return array
     */
    public function prepareResultData($response)
    {
        $products = $this->getProducts($response->getDocumentsCollection());
        $data = [
            'total_count' => $response->getNumFound(),
            'items_ids'   => $products['items_ids'],
            'items'       => $products['items'],
            'page_info'   => [
                'page_size'    => count($response->getDocumentsCollection()),
                'current_page' => $response->getCurrentPage(),
                'total_pages'  => $response->getNumFound()
            ],
            'facets'      => $this->prepareFacets($response->getFacets()),
            'stats'       => $response->getStats()
        ];

        return $data;
    }

    /**
     * @param array $facets
     * @return array
     */
    public function prepareFacets($facets)
    {
        $preparedFacets = [];

        foreach ($facets as $field => $values) {
            $preparedValues = [];

            foreach ($values as $valueId => $count) {
                $preparedValues[] = [
                    'value_id' => $valueId,
                    'count'    => $count
                ];
            }

            $preparedFacets[] = [
                'code'   => $field,
                'values' => $preparedValues
            ];
        }

        return $preparedFacets;
    }

    /**
     * @param $facets
     * @return array
     * @throws NoSuchEntityException
     */
    public function prepareFacetsAndStats($facets)
    {
        $solrFacets = [];

        foreach ($facets as $key => $facet) {
            if ($key === "virtual_category_params") {
                $solrFacets['virtual_category_params'] = $this->prepareVirtualCategoryParamsAsFilter($facet);
            } else {
                if ($attributeCode = $this->getSolrAttributeCode($facet)) {
                    if (strpos($attributeCode, '_facet') !== false) {
                        $solrFacets['facet'][] = $attributeCode;
                    } else {
                        $solrFacets['stat'][] = $attributeCode;
                    }
                }
            }
        }

        return $solrFacets;
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
            if (isset($codeAndValue[1]) && ($codeAndValue[1])) {
                $attributeValue = $codeAndValue[1];
                if (in_array($attributeCode, self::$attributeMapping)) {
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
                        $skus = 'sku_s=' . preg_replace('/[,]{2,}/i', ',', rtrim($filterValue, ','));
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
     * @param $documentCollection
     * @param string $idType
     * @return array
     */
    public function getProducts($documentCollection)
    {
        $products = [];
        $productIds = [];

        $i = 300; // default for product order in search

        /** @var Document $productDocument */
        foreach ($documentCollection as $productDocument) {
            $this->eventManager->dispatch('prepare_msproduct_resolver_result_before', ['productDocument' => $productDocument]);

            $productData = [];
            foreach ($productDocument->getFields() as $field) {
                if ($field->getName() == 'sku') {
                    $productIds[] = $field->getValue();
                }
                $productData[$field->getName()] = $field->getValue();
            }

            $this->eventManager->dispatch('prepare_msproduct_resolver_result_after', ['productData' => $productData]);
            $products[$i] = $productData;
            $i++;
        }

        ksort($productIds);
        ksort($products);

        return ['items' => $products, 'items_ids' => $productIds];
    }

    /**
     * @param $filters
     * @return array
     */
    public function getActiveAttributesCode($filters)
    {
        $activeAttributesCode = [];

        foreach ($filters as $filter) {
            $codeAndValue = explode('=', $filter);
            $activeAttributesCode[] = $codeAndValue[0];
        }

        return $activeAttributesCode;
    }

    /**
     * @param Document $document
     * @return array
     */
    protected function prepareProductAttributes(Document $document): array
    {
        $attributes = [];
        /** @var Document\Field $field */
        foreach ($document->getFields() as $field) {
            if (in_array($field->getName(), self::$attributesToSkip)) {
                continue;
            }

            $attribute = [];

            $name = $field->getName();
            $value = $field->getValue();
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $attribute['attribute_code'] = $name;
            $attribute['value'] = $value;

            $attributes[] = $attribute;
        }

        return $attributes;
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
     * @param array $filters
     *
     * @return array
     * @throws LocalizedException
     */
    public function prepareFiltersByArgsFilter(array $filters)
    {
        $preparedFilters = [];
        foreach ($filters as $key => $filter) {
            if ($key === 'attributes') {
                $preparedFilters = array_merge($preparedFilters, $this->prepareAttributes($filter));
            } else {
                $field = $this->queryHelper->getFieldByAttributeCode($key, $filter);
                $preparedFilters[] = [$field];
            }
        }

        return $preparedFilters;
    }

    /**
     * @param $attributes
     * @return array
     * @throws LocalizedException
     */
    public function prepareAttributes($attributes)
    {
        $preparedFilters = [];

        foreach ($attributes as $attribute => $value) {
            $filterData = explode('=', $value);
            if (count($filterData) < 2) {
                continue;
            }
            if ($field = $this->queryHelper->getFieldByAttributeCode(
                $filterData[0],
                $this->prepareFilterValue(['eq' => $filterData[1]])
            )) {
                $preparedFilters[] = [$field];
            }
        }

        return $preparedFilters;
    }

    /**
     * @param array $value
     *
     * @return string
     */
    protected function prepareFilterValue(array $value)
    {
        return $value;

        // temporary leave below

        $key = key($value);
        if (count($value) > 1) {
            return implode(',', $value);
        }

        if ($key) {
            if (isset($value[$key]) && !is_numeric($value[$key])) {
                return $value[$key];
            }
            switch ($key) {
                case 'eq':
                    return (string)$value[$key];
                case 'gt':
                    return (string)($value[$key] + 1) . '-*';
                case 'lt':
                    return (string)'*-' . ($value[$key] - 1);
                case 'gteq':
                    return (string)$value[$key] . '-*';
                case 'lteq':
                    return (string)'*-' . $value[$key];
                default:
                    return (string)$value[$key];

            }
        }

        return '';
    }

    /**
     * @param $sort
     *
     * @return Field
     * @throws LocalizedException
     */
    protected function prepareSortField($sort)
    {
        return $this->queryHelper->getFieldByAttributeCode($sort);
    }
}
