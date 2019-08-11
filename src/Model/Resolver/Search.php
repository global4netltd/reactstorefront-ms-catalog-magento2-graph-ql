<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\Query;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Search as SearchHelper;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Search
 * @package G4NReact\MsCatalogMagento2GraphQl\Model\Resolver
 */
class Search extends AbstractResolver
{
    /**
     * @var SearchHelper
     */
    protected $searchHelper;

    /**
     * Search constructor.
     * @param SearchHelper $searchHelper
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
        SearchHelper $searchHelper,
        CacheInterface $cache,
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        Json $serializer,
        LoggerInterface $logger,
        ConfigHelper $configHelper,
        Query $queryHelper,
        EventManager $eventManager
    ) {
        $this->searchHelper = $searchHelper;
        parent::__construct($cache, $deploymentConfig, $storeManager, $serializer, $logger, $configHelper, $queryHelper, $eventManager);
    }

    /**
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return mixed|Value
     * @throws GraphQlInputException
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
        if (!isset($args['query'])) {
            throw new GraphQlInputException(
                __("'query' input argument is required.")
            );
        }

        $searchText = $args['query'] ?? '';
        $isAutosuggest = (isset($args['autosuggest']) && $args['autosuggest']) ? true : false;

        // get search term from solr
        $searchTerm = $this->getSearchTermFromSearchEngine($searchText);

        // @ToDo: handle synonyms, somehow...

        // update search term in magento
        // @ToDo - create new if not exist or update search count in magento by id if not autosuggest
        if (!$isAutosuggest) {
            $this->updateMagentoSearchTerm($searchTerm);
        }

        // if redirect -> set redirect info
        if ($canonicalUrl = $searchTerm->getFieldValue('redirect')) {
            return [
                'redirect' => [
                    'type'          => 'REDIRECT',
                    'id'            => 301,
                    'canonical_url' => $canonicalUrl,
                ]
            ];
        }

        // else -> set search in args and msProducts will do the rest
        $finalSearchText = $searchTerm->getFieldValue('search_text') ?: $searchText;
        $argsForMsProducts = ['search' => $finalSearchText];
        $dataObject = new DataObject(['args' => $argsForMsProducts]);
        $this->eventManager->dispatch(
            'prepare_mssearch_resolver_args_after',
            ['search_text' => $searchText, 'args' => $dataObject, 'search_term' => $searchTerm]
        );

        $context->args = array_merge($args, $dataObject->getData('args'));

        return [];
    }

    /**
     * @param string $searchText
     * @return Document
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws Exception
     */
    protected function getSearchTermFromSearchEngine(string $searchText)
    {
        $searchEngineConfig = $this->configHelper->getConfiguration();
        $searchEngineClient = ClientFactory::create($searchEngineConfig);

        $getSearchTermQuery = $searchEngineClient->getQuery();

        $getSearchTermQuery->addFilters([
            [$this->queryHelper->getFieldByProductAttributeCode(
                'store_id',
                $this->storeManager->getStore()->getId()
            )],
            [$this->queryHelper->getFieldByProductAttributeCode(
                'object_type',
                'search_term'
            )],
            [new Document\Field('query_text', $searchText, Document\Field::FIELD_TYPE_STRING, true, false)],
        ]);
        $response = $getSearchTermQuery->getResponse();
        /** @var Document $searchTerm */
        $searchTerm = $response->getFirstItem();

        return $searchTerm;
    }

    /**
     * @param Document $searchTerm
     */
    protected function updateMagentoSearchTerm(Document $searchTerm)
    {
        $searchText = $searchTerm->getFieldValue('search_text') ?: '';

        if (!$searchText) {
            return;
        }

        $magentoSearchQuery = $this->searchHelper->getMagentoSearchQuery((string)$searchText);
        if ($magentoSearchQuery) {
            $this->searchHelper->executeMagentoSearchQuery($magentoSearchQuery);
        }
    }
}
