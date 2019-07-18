<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\BaseQuery as QueryHelper;
use G4NReact\MsCatalogMagento2GraphQl\Model\Resolver\DataProvider\AttributeOption as AttributeOptionDataProvider;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class AttributeOption
 * @package G4NReact\MsCatalogMagento2GraphQl\Model\Resolver
 */
class AttributeOption extends AbstractResolver
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
     * AttributeOption constructor
     *
     * @param CacheInterface $cache
     * @param DeploymentConfig $deploymentConfig
     * @param StoreManagerInterface $storeManager
     * @param Json $serializer
     * @param LoggerInterface $logger
     * @param ConfigHelper $configHelper
     * @param QueryHelper $queryHelper
     * @param EventManager $eventManager
     * @param AttributeOptionDataProvider $attributeOptionDataProvider
     */
    public function __construct(
        CacheInterface $cache,
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        Json $serializer,
        LoggerInterface $logger,
        ConfigHelper $configHelper,
        QueryHelper $queryHelper,
        EventManager $eventManager,
        AttributeOptionDataProvider $attributeOptionDataProvider
    ) {
        $this->attributeOptionDataProvider = $attributeOptionDataProvider;

        parent::__construct($cache, $deploymentConfig, $storeManager, $serializer, $logger, $configHelper, $queryHelper, $eventManager);
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

        return $data;
    }
}
