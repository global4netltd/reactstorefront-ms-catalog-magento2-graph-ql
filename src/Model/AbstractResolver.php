<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Model;

use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalogMagento2\Helper\Query;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class AbstractResolver
 * @package G4NReact\MsCatalogMagento2GraphQl\Model\Resolver
 */
abstract class AbstractResolver implements ResolverInterface
{
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
     * @var Query
     */
    protected $queryHelper;

    /**
     * @var EventManager
     */
    protected $eventManager;

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
     */
    public function __construct(
        CacheInterface $cache,
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        Json $serializer,
        LoggerInterface $logger,
        ConfigHelper $configHelper,
        Query $queryHelper,
        EventManager $eventManager
    )
    {
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
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     *
     * @return mixed|Value
     */
    public abstract function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    );

    /**
     * @param Document $documentData
     * @param array $queryFields
     * @param string $eventType
     *
     * @return array
     */
    public function prepareDocumentResult(Document $documentData, array $queryFields = [], string $eventType)
    {
        $this->eventManager->dispatch('prepare_' . $eventType . '_resolver_result_before', ['documentData' => $documentData]);

        if (empty($documentData)) {
            return [];
        }

        $data = [];
        foreach ($queryFields as $fieldName => $value) {
            $data[$fieldName] = $this->parseToString($documentData->getFieldValue($fieldName));
        }

        $dataObject = new DataObject(['data' => $data]);

        $this->eventManager->dispatch('prepare_' . $eventType . '_resolver_result_after', ['preparedData' => $dataObject]);

        return $dataObject->getData('data');
    }

    /**
     * @param $field
     *
     * @return string
     */
    public function parseToString($field)
    {
        return is_array($field) ? implode(', ', $field) : $field;
    }

    /**
     * @param $url
     *
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
     * @param array $filters
     * @param string $type
     *
     * @return array
     * @throws LocalizedException
     */
    public function prepareFiltersByArgsFilter(array $filters, $type = 'product')
    {
        $preparedFilters = [];
        foreach ($filters as $key => $filter) {
            if ($key === 'attributes') {
                $preparedFilters = array_merge($preparedFilters, $this->prepareAttributes($filter));
            } else {
                switch ($type) {
                    case 'product':
                        $field = $this->queryHelper->getFieldByProductAttributeCode($key, $filter);
                        break;
                    case 'category':
                        $field = $this->queryHelper->getFieldByCategoryAttributeCode($key, $filter);
                        break;
                }
                
                $preparedFilters[] = [$field];
            }
        }

        return $preparedFilters;
    }

    /**
     * @param $attributes
     *
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
            $valueParts = explode(',', $filterData[1]);
            if (count($valueParts) > 1) {
                $fieldValue = ['in' => $valueParts];
            } else {
                $fieldValue = ['eq' => $filterData[1]];
            }
            if ($field = $this->queryHelper->getFieldByAttributeCode(
                $filterData[0],
                $this->prepareFilterValue($fieldValue)
            )) {
                $field->setExcluded(true);
                $preparedFilters[] = [$field];
            }
        }

        return $preparedFilters;
    }
}
