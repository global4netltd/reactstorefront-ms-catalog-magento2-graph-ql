<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\Query as QueryHelper;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
use G4NReact\MsCatalogSolr\Response;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Event\Manager as EventManager;
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
    protected $deploymentConfig;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var Json
     */
    protected $serializer;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var ConfigHelper
     */
    protected $queryHelper;

    /**
     * @var EventManager
     */
    protected $eventManager;

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
        'category_id'  => 'category_ids_i_mv',
        'sku'          => 'sku_s',
        'id'           => 'object_id',
        'price'        => 'price_f',
        'max_sale_qty' => 'max_sale_qty_i',
        'min_sale_qty' => 'min_sale_qty_i',
        'store_id'     => 'store_id_s',
    ];

    /**
     * @var array
     */
    public static $sortMapping = [
        'score' => 'score',
        'name'  => 'name_s',
        'price' => 'price_f',
    ];

    /**
     * @var array
     */
    public static $defaultAttributes = [
        'category',
        'price'
    ];

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
     * Products constructor
     *
     * @param CacheInterface $cache
     * @param DeploymentConfig $deploymentConfig
     * @param StoreManagerInterface $storeManager
     * @param Json $serializer
     * @param LoggerInterface $logger
     * @param ConfigHelper $configHelper
     * @param QueryHelper $queryHelper
     */
    public function __construct(
        CacheInterface $cache,
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        Json $serializer,
        LoggerInterface $logger,
        ConfigHelper $configHelper,
        QueryHelper $queryHelper,
        EventManager $eventManager
    ) {
        $this->cache = $cache;
        $this->deploymentConfig = $deploymentConfig;
        $this->storeManager = $storeManager;
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->configHelper = $configHelper;
        $this->queryHelper = $queryHelper;
        $this->eventManager = $eventManager;
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

        $client = ClientFactory::getInstance($this->configHelper->getConfiguration());

        $query = $client->getQuery();

        $queryFields = $this->parseQueryFields($info);

        $storeId = $this->storeManager->getStore()->getId();
        $query->addFilters([
            [$this->queryHelper->getFieldByAttributeCode('store_id', $storeId, 'catalog_category')],
            [$this->queryHelper->getFieldByAttributeCode('object_type', 'product', 'catalog_category')],
        ]);

        $this->resolveInfo = $info->getFieldSelection(3);

        // venia outside of variables, he asks for __typename
        $limit = (isset($this->resolveInfo['items']) && isset($this->resolveInfo['items']['__typename'])) ? 2 : 1;
        if ((isset($this->resolveInfo['items']) && count($this->resolveInfo['items']) <= $limit && isset($this->resolveInfo['items']['sku']))
            || (isset($this->resolveInfo['items_ids']))
        ) {
            $maxPageSize = 10000;

//            $query->addFilter();
            $args['fields_to_fetch'] = [self::$attributeMapping['sku']];
            $args['id_type'] = self::$idTypeMapping['SKU'];
            if (isset($args['filter']) && isset($args['filter']['id_type']) && ($idType = $args['filter']['id_type'])) {
                $args['fields_to_fetch'] = [self::$attributeMapping[strtolower($idType)]];
                $args['id_type'] = self::$idTypeMapping[$idType];
            }
        } elseif (isset($args['search']) && $args['search']) {
            $maxPageSize = 3000;
        } else {
            $maxPageSize = 100;
        }

        $pageSize = (isset($args['pageSize']) && ($args['pageSize'] < $maxPageSize)) ? $args['pageSize'] : $maxPageSize;

        $query->setPageSize($pageSize);

        if (isset($args['search']) && $args['search']) {
            $searchText = Parser::parseSearchText($args['search']);
            $query->setQueryText($searchText);

            if (isset($args['filter'])) {
                $args['filter'] = $this->getFilters($args['filter']);
            }

            if (!isset($args['sort'])) {
                $args['sort'] = ['sort_by' => 'score', 'sort_order' => 'desc'];
            }
        }

        $productResult = $query->getResponse();

        $products = $this->prepareResultData($productResult);

        return $products;
    }

    /**
     * @param Response $result
     * @param $idType
     * @param $forSearch
     * @return array
     */
    public function prepareResultData($result)
    {
        $products = $this->getProducts($result->getDocumentsCollection());

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
     * @param $idType
     * @param bool $forSearch
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
}
