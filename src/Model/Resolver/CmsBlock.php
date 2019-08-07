<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;

class CmsBlock extends AbstractResolver
{
    /**
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     *
     * @return array|Value|mixed
     * @throws NoSuchEntityException
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!isset($args['id']) && is_int($args['id'])) {
            throw new GraphQlInputException(__('id for cms block should be specified'));
        }

        return $this->getCmsBlockFromSearchEngine($args['id'], $info->getFieldSelection());
    }

    /**
     * @param int $id
     * @param array $queryFields
     *
     * @return array
     * @throws NoSuchEntityException
     */
    public function getCmsBlockFromSearchEngine(int $id, $queryFields = [])
    {
        $storeId = $this->storeManager->getStore()->getId();
        $searchEngineConfig = $this->configHelper->getConfiguration();
        $searchEngineClient = ClientFactory::create($searchEngineConfig);

        $query = $searchEngineClient->getQuery();

        foreach ($queryFields as $name => $field) {
            $query->addFieldToSelect(
                $this->queryHelper->getFieldByCmsBlockColumnName(
                    $name
                )
            );
        }
        $query->addFilters([
            [
                $this->queryHelper->getFieldByCmsBlockColumnName(
                    'store_id', $storeId
                )
            ],
            [
                $this->queryHelper->getFieldByCmsBlockColumnName(
                    'object_type', HelperCmsField::OBJECT_TYPE
                ),
            ],
            [
                $this->queryHelper->getFieldByCmsBlockColumnName(
                    'id', $id
                )
            ]
        ]);

        /** @var ResponseInterface $cmsBlockResult */
        $cmsBlockResult = $query->getResponse();

        if ($cmsBlockResult->getNumFound()) {
            foreach ($cmsBlockResult->getDocumentsCollection() as $cmsBlock) {
                $solrCmsBlock = $this->prepareDocumentResult($cmsBlock, $queryFields, 'mscmsblock');
            }
        }

        return $solrCmsBlock ?? [];
    }
}
