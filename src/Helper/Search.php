<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Helper;

use Exception;
use G4NReact\MsCatalog\Spellcheck\SpellcheckResponseInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Stdlib\StringUtils as StdlibString;
use Magento\Search\Helper\Data as MagentoSearchHelper;
use Magento\Search\Model\Query as SearchQuery;
use Magento\Search\Model\QueryFactory as SearchQueryFactory;
use Magento\Search\Model\ResourceModel\Query as SearchQueryResource;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class Search
 * @package G4NReact\MsCatalogMagento2GraphQl\Helper
 */
class Search extends AbstractHelper
{
    /**
     * @var MagentoSearchHelper
     */
    protected $magentoSearchHelper;

    /**
     * @var StdlibString
     */
    protected $string;

    /**
     * @var SearchQueryFactory
     */
    protected $searchQueryFactory;

    /**
     * @var SearchQuery
     */
    protected $query;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var SearchQueryResource
     */
    protected $searchQueryResource;

    /**
     * Search constructor
     *
     * @param Context $context
     * @param MagentoSearchHelper $magentoSearchHelper
     * @param StdlibString $string
     * @param SearchQueryFactory $searchQueryFactory
     * @param StoreManagerInterface $storeManager
     * @param SearchQueryResource $searchQueryResource
     */
    public function __construct(
        Context $context,
        MagentoSearchHelper $magentoSearchHelper,
        StdlibString $string,
        SearchQueryFactory $searchQueryFactory,
        StoreManagerInterface $storeManager,
        SearchQueryResource $searchQueryResource
    ) {
        $this->magentoSearchHelper = $magentoSearchHelper;
        $this->string = $string;
        $this->searchQueryFactory = $searchQueryFactory;
        $this->storeManager = $storeManager;
        $this->searchQueryResource = $searchQueryResource;

        parent::__construct($context);
    }

    /**
     * @param string $queryText
     * @return SearchQuery|null
     */
    public function getMagentoSearchQuery(string $queryText): SearchQuery
    {
        try {
            if (!$this->query || !$this->query instanceof \Magento\Search\Model\QueryInterface) {
                $query = $this->searchQueryFactory->create();
                $this->query = $query;
                $maxQueryLength = $this->magentoSearchHelper->getMaxQueryLength();
                $minQueryLength = $this->magentoSearchHelper->getMinQueryLength();
                $rawQueryText = $this->getRawQueryText($queryText);
                $preparedQueryText = $this->getPreparedQueryText($rawQueryText, $maxQueryLength);
                $query->loadByQueryText($preparedQueryText);
                if (!$query->getId()) {
                    $query->setQueryText($preparedQueryText);
                }
                $query->setIsQueryTextExceeded($this->isQueryTooLong($rawQueryText, $maxQueryLength));
                $query->setIsQueryTextShort($this->isQueryTooShort($rawQueryText, $minQueryLength));
            }

            return $this->query;
        } catch (Exception $e) {
            $this->_logger->error('Get Magento Search Query Exception', ['message' => $e->getMessage(), 'exception' => $e]);
            return $this->query;
        }
    }

    /**
     * @param SearchQuery $query
     */
    public function executeMagentoSearchQuery(SearchQuery $query)
    {
        try {
            $query->setStoreId($this->storeManager->getStore()->getId());
            if (($query->getQueryText() != '') && !$query->getIsProcessed()) {
                $this->searchQueryResource->saveIncrementalPopularity($query);
                $query->setIsProcessed(1);
            }
        } catch (Exception $e) {
            $this->_logger->error('Execute Magento Search Query Exception', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * @param SearchQuery $query
     */
    public function updateSearchQueryNumResults(SearchQuery $query)
    {
        try {
            $query->setStoreId($this->storeManager->getStore()->getId());
            $this->searchQueryResource->saveNumResults($query);
        } catch (Exception $e) {
            $this->_logger->error('Update Magento Search Query Num Results Exception', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * @param string $queryText
     * @return string
     */
    public function getRawQueryText($queryText)
    {
        return ($queryText === null || is_array($queryText))
            ? ''
            : $this->string->cleanString(trim($queryText));
    }

    /**
     * @param string $queryText
     * @param int|string $maxQueryLength
     * @return string
     */
    public function getPreparedQueryText($queryText, $maxQueryLength)
    {
        if ($this->isQueryTooLong($queryText, $maxQueryLength)) {
            $queryText = $this->string->substr($queryText, 0, $maxQueryLength);
        }

        return $queryText;
    }

    /**
     * @param string $queryText
     * @param int|string $maxQueryLength
     * @return bool
     */
    public function isQueryTooLong($queryText, $maxQueryLength)
    {
        return ($maxQueryLength !== '' && $this->string->strlen($queryText) > $maxQueryLength);
    }

    /**
     * @param string $queryText
     * @param int|string $minQueryLength
     * @return bool
     */
    public function isQueryTooShort($queryText, $minQueryLength)
    {
        return ($this->string->strlen($queryText) < $minQueryLength);
    }

    /**
     * @param string $origText
     * @param SpellcheckResponseInterface $spellcheckResponse
     * @return string[]
     */
    public function getAlternativeSearchTexts(string $origText, SpellcheckResponseInterface $spellcheckResponse): array
    {
        $result = [];
        foreach ($spellcheckResponse->getSpellCorrectSuggestions() as $suggestion) {
            if ($suggestion->getOriginalFrequency() > 0) {
                continue;
            }
            $origWord = $suggestion->getText();
            foreach ($suggestion->getSortedAlternatives() as $alternative) {
                $replaceWord = $alternative->getText();
                $newValues = [str_replace($origWord, $replaceWord, $origText)];
                foreach ($result as $value){
                    $newValues[] = str_replace($origWord, $replaceWord, $value);
                }

                $result = array_unique(array_merge($result, $newValues));
            }
        }
        return $result;
    }
}
