<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\Query;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
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
use Magento\Search\Model\Query as SearchQuery;
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

        $searchText = isset($args['query']) ? Parser::parseSearchText($args['query']) : '';
        if (!$searchText) {
            return [];
        }

        $magentoSearchQuery = null;
        $isAutosuggest = (isset($args['autosuggest']) && $args['autosuggest']) ? true : false;

        $searchObject = new DataObject();
        $originalSearchText = $searchText;
        $searchObject->setText($searchText);
        /** To be able to get original input in case when one observer change it*/
        $searchObject->setOriginalText($originalSearchText);
        $this->eventManager->dispatch(
            'prepare_mssearch_search_text_before', ['search_object' => $searchObject]
        );
        $searchText = $searchObject->getText();

        // get search term from solr
        $searchTermDocument = $this->getSearchTermFromSearchEngine($searchText);

        // @ToDo: handle synonyms, somehow...

        // update search term in magento
        if (!$isAutosuggest) {
            $magentoSearchQuery = $this->updateMagentoSearchTerm($searchText, $searchTermDocument);
        }

        // if redirect -> set redirect info
        if (!$isAutosuggest && $canonicalUrl = $this->sanitizeCanonicalUrl($searchTermDocument->getFieldValue('redirect'))) {
            $return = [
                'redirect' => [
                    'type'          => 'REDIRECT',
                    'id'            => 301,
                    'canonical_url' => $canonicalUrl,
                ]
            ];
            $argsToSet = array_merge($args, ['redirect' => true]);
        } else {
            // else -> set search in args and msProducts will do the rest
            $finalSearchText = $searchTermDocument->getFieldValue('query_text') ?: $searchText;
            $argsForMsProducts = ['search' => $finalSearchText];
            $dataObject = new DataObject(['args' => $argsForMsProducts, 'return' => []]);
            $this->eventManager->dispatch(
                'prepare_mssearch_resolver_args_after',
                [
                    'search_text' => $searchText,
                    'original_search_text' => $originalSearchText,
                    'args' => $dataObject,
                    'search_term' => $searchTermDocument
                ]
            );
            $argsToSet = array_merge($args, $dataObject->getData('args'));
            $return = $dataObject->getData('return');
        }

        $context->args = $argsToSet;
        $context->magentoSearchQuery = $magentoSearchQuery;

        return $return ?: [];
    }

    /**
     * @param string $searchText
     * @return string
     * @deprecated
     */
    public function parseSearchText(string $searchText): string
    {
        return mb_strtolower(strip_tags(stripslashes($searchText)));
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
     * @param string $searchText
     * @param Document $searchTerm
     * @return null|SearchQuery
     */
    protected function updateMagentoSearchTerm(string $searchText, Document $searchTerm)
    {
        $searchText = $searchTerm->getFieldValue('query_text') ?: $searchText;

        if (!$searchText) {
            return;
        }

        $magentoSearchQuery = $this->searchHelper->getMagentoSearchQuery((string)$searchText);
        if ($magentoSearchQuery) {
            $this->searchHelper->executeMagentoSearchQuery($magentoSearchQuery);
        }

        return $magentoSearchQuery ?: null;
    }

    /**
     * @param string|null $url
     * @return string|null
     */
    protected function sanitizeCanonicalUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        } else {
            return '/' . ltrim((string)$url, '/');
        }
    }
}
