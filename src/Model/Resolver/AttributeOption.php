<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalogMagento2GraphQl\Model\Resolver\DataProvider\AttributeOption as AttributeOptionDataProvider;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Class AttributeOption
 * @package G4NReact\MsCatalogMagento2GraphQl\Model\Resolver
 */
class AttributeOption implements ResolverInterface
{
    /**
     * @var string
     */
    const CACHE_KEY = 'G4N_FILTER_NAME_';

    /**
     * @var AttributeOptionDataProvider
     */
    protected $attributeOptionDataProvider;

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
     * @param AttributeOptionDataProvider $attributeOptionDataProvider
     * @param CacheInterface $cache
     * @param Json $serializer
     */
    public function __construct(
        AttributeOptionDataProvider $attributeOptionDataProvider,
        CacheInterface $cache,
        Json $serializer
    ) {
        $this->attributeOptionDataProvider = $attributeOptionDataProvider;
        $this->cache = $cache;
        $this->serializer = $serializer;
    }

    /**
     * Fetches the data from persistence models and format it according to the GraphQL schema.
     *
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @throws Exception
     * @return mixed|Value
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $data = $this->attributeOptionDataProvider->getData($value);

        if (!is_array($data)) {
            $data = [];
        }

//        $attributeOptions = [];
//        foreach ($data as $option) {
//            $attributeOptions['option_id'] = $option['value'] ?? null;
//            $attributeOptions['label'] = $option['label'] ?? null;
//        }
//
//        return $attributeOptions;

        return $data;
    }
}
