<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver\DataProvider;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class AttributeOption
 * @package G4NReact\MsCatalogMagento2GraphQl\Model\Resolver\DataProvider
 */
class AttributeOption
{
    /**
     * Attribute options cache key
     */
    const CACHE_KEY = 'G4N_MS-CAT-ATTR-OPT_CACHE';

    /**
     * @var EavConfig
     */
    protected $eavConfig;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var Json
     */
    protected $serializer;

    /**
     * AttributeOption constructor
     *
     * @param EavConfig $eavConfig
     * @param StoreManagerInterface $storeManager
     * @param CacheInterface $cache
     * @param Json $serializer
     */
    public function __construct(
        EavConfig $eavConfig,
        StoreManagerInterface $storeManager,
        CacheInterface $cache,
        Json $serializer
    ) {
        $this->eavConfig = $eavConfig;
        $this->storeManager = $storeManager;
        $this->cache = $cache;
        $this->serializer = $serializer;
    }

    /**
     * @param $value
     * @return array
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function getData(array $value)
    {
        if (isset($value['attribute_options']) && !empty($value['attribute_options'])) {
            return $value['attribute_options'];
        }

        $attributeCode = $value['attribute_code'];
        $cacheIdentifier = self::CACHE_KEY . ':' . $attributeCode . ':' . $this->storeManager->getStore()->getId();

        if ($cached = $this->cache->load($cacheIdentifier)) {
            $attributeOptions = $this->serializer->unserialize($cached);
            if ($attributeOptions) {
                return $attributeOptions;
            }
        }

        $attribute = $this->eavConfig->getAttribute('catalog_product', $attributeCode);
        $attributeOptions = $attribute->getSource()->getAllOptions();

        if (is_array($attributeOptions)) {
            $this->cache->save($this->serializer->serialize($attributeOptions), $cacheIdentifier, [], 86400);
        }

        return $attributeOptions;
    }
}
