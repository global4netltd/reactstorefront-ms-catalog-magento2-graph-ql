<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\Query as QueryHelper;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
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
     * @var QueryHelper
     */
    protected $queryHelper;

    /**
     * @var EventManager
     */
    protected $eventManager;

    /**
     * AbstractResolver constructor
     *
     * @param CacheInterface $cache
     * @param DeploymentConfig $deploymentConfig
     * @param StoreManagerInterface $storeManager
     * @param Json $serializer
     * @param LoggerInterface $logger
     * @param ConfigHelper $configHelper
     * @param QueryHelper $queryHelper
     * @param EventManager $eventManager
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
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return mixed|Value
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        // TODO: Implement resolve() method.
        return null;
    }
}
