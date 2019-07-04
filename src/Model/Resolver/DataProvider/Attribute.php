<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver\DataProvider;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection as ProductAttributeCollection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class Attribute
 * @package G4NReact\MsCatalogMagento2GraphQl\Model\Resolver\DataProvider
 */
class Attribute
{
    /**
     * Attribute cache key
     */
    const CACHE_KEY = 'G4N_MS-CAT-ATTR_CACHE';

    /**#@+
     * @var string
     */
    const ATTRIBUTE_TYPE_MULTISELECT = 'multiselect';
    const ATTRIBUTE_TYPE_RANGE = 'range';
    const ATTRIBUTE_TYPE_SELECT = 'select';
    /**#@-*/

    /**
     * @var ProductAttributeCollectionFactory
     */
    protected $productAttributeCollectionFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var mixed
     */
    protected $serializer;

    /**
     * Map attribute code to attribute type
     * @var array
     */
    public static $attributeTypeMap = [
        'price'    => self::ATTRIBUTE_TYPE_RANGE,
        'category' => self::ATTRIBUTE_TYPE_MULTISELECT
    ];

    /**
     * Attribute constructor
     *
     * @param ProductAttributeCollectionFactory $productAttributeCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param CacheInterface $cache
     * @param Json|null $serializer
     */
    public function __construct(
        ProductAttributeCollectionFactory $productAttributeCollectionFactory,
        StoreManagerInterface $storeManager,
        CacheInterface $cache,
        Json $serializer
    ) {
        $this->productAttributeCollectionFactory = $productAttributeCollectionFactory;
        $this->storeManager = $storeManager;
        $this->cache = $cache;
        $this->serializer = $serializer;
    }

    /**
     * @param $args
     * @return array
     * @throws NoSuchEntityException
     */
    public function getData($args)
    {
        $allAttributesCodes = $args['attributeCodes'];
        $attributesFromCache = [];
        $attributesFromDb = [];

        $storeId = $this->storeManager->getStore()->getId();

        for ($i = 0; $i < count($args['attributeCodes']); $i++) {
            $attributeCode = $args['attributeCodes'][$i];
            $cacheIdentifier = self::CACHE_KEY . ':' . $attributeCode . ':' . $storeId;
            if ($cached = $this->cache->load($cacheIdentifier)) {
                if ($attributeData = $this->serializer->unserialize($cached)) {
                    unset($allAttributesCodes[$i]);
                    $attributesFromCache[$attributeCode] = $attributeData;
                }
            }
        }

        if (count($allAttributesCodes) > 0) {
            /** @var ProductAttributeCollection $productAttributeCollection */
            $productAttributeCollection = $this->productAttributeCollectionFactory->create();
            $productAttributeCollection
                ->addFieldToFilter('attribute_code', ['in' => $allAttributesCodes]);

            foreach ($productAttributeCollection as $attribute) {
                $attributesFromDb[$attribute->getAttributeCode()] = $attribute->getData();
            }
        }

        if (is_array($attributesFromDb)) {
            foreach ($attributesFromDb as $attributeCode => &$attributeData) {
                $backendType = $attributeData['backend_type'] ?? '';
                $frontendInput = $attributeData['frontend_input'] ?? '';
                $attributeData['attribute_type'] = self::$attributeTypeMap[$attributeCode]
                    ?? $this->getFeatureTypeByAttributeType($backendType, $frontendInput);

                $cacheIdentifier = self::CACHE_KEY . ':' . $attributeCode . ':' . $this->storeManager->getStore()->getId();
                $this->cache->save($this->serializer->serialize($attributeData), $cacheIdentifier, [], 86400);
            }
        }

        $attributes = array_merge($attributesFromCache, $attributesFromDb);

        return $attributes;
    }

    /**
     * @param string $backendType
     * @param string $frontendInput
     * @return string
     */
    public function getFeatureTypeByAttributeType($backendType, $frontendInput)
    {
        switch (true) {
            case ($backendType === 'int' && $frontendInput === 'select'):
                return self::ATTRIBUTE_TYPE_SELECT;
                break;
            case ($backendType === 'decimal' && $frontendInput === 'number'):
                return self::ATTRIBUTE_TYPE_RANGE;
                break;
            case ($backendType === 'varchar' && $frontendInput === 'multiselect'):
                return self::ATTRIBUTE_TYPE_MULTISELECT;
                break;
            default:
                return '';
                break;
        }
    }
}
