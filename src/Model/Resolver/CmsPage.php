<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\ResponseInterface;
use G4NReact\MsCatalogMagento2GraphQl\Model\AbstractResolver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use G4NReact\MsCatalogMagento2\Helper\Cms\Field as HelperCmsField;

/**
 * Class CmsPage
 * @package G4NReact\MsCatalogMagento2GraphQl\Model\Resolver
 */
class CmsPage extends AbstractResolver
{
    /**
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!isset($args['id'])) {
            throw new GraphQlInputException(__('id for cms page should be specified'));
        }

        return $this->getCmsPageFromSearchEngine($args['id'], $info->getFieldSelection(0));
    }


    /**
     * @param int $id
     * @param array $queryFields
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getCmsPageFromSearchEngine(int $id, $queryFields = [])
    {
        $storeId = $this->storeManager->getStore()->getId();
        $searchEngineConfig = $this->configHelper->getConfiguration();
        $searchEngineClient = ClientFactory::create($searchEngineConfig);

        $query = $searchEngineClient->getQuery();

        foreach ($queryFields as $name => $field) {
            $query->addFieldToSelect(
                $this->queryHelper->getFieldByCmsPageColumnName(
                    $name
                )
            );
        }
        $query->addFilters([
            [
                $this->queryHelper->getFieldByCmsPageColumnName(
                    'store_id', $storeId
                )
            ],
            [
                $this->queryHelper->getFieldByCmsPageColumnName(
                    'object_type', HelperCmsField::OBJECT_TYPE
                ),
            ],
            [
                $this->queryHelper->getFieldByCmsPageColumnName(
                    'id', $id
                )
            ]
        ]);

        /** @var ResponseInterface $cmsPageResult */
        $cmsPageResult = $query->getResponse();

        if ($cmsPageResult->getNumFound()) {
            foreach ($cmsPageResult->getDocumentsCollection() as $cmsPage) {
                $solrCmsPage = $this->prepareDocumentResult($cmsPage, $queryFields, 'mscmspage');
            }
        }

        return $solrCmsPage ?? [];
    }

}
