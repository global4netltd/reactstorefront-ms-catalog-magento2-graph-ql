<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use G4NReact\MsCatalogMagento2\Helper\Cms\CmsBlockField;
use G4NReact\MsCatalogMagento2GraphQl\Model\AbstractResolver;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use G4NReact\MsCatalog\Client\ClientFactory;

/**
 * Class CmsBlock
 * @package G4NReact\MsCatalogMagento2GraphQl\Model\Resolver
 */
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
        if (!isset($args['identifiers']) && is_int($args['identifiers'])) {
            throw new GraphQlInputException(__('identifiers for cms block should be specified'));
        }

        return $this->getCmsBlockFromSearchEngine($args['identifiers'], $info->getFieldSelection(2));
    }

    /**
     * @param array $identifiers
     * @param array $queryFields
     * @return array
     * @throws NoSuchEntityException
     * @throws \Exception
     */
    public function getCmsBlockFromSearchEngine(array $identifiers, $queryFields = [])
    {
        $storeId = $this->storeManager->getStore()->getId();
        $searchEngineConfig = $this->configHelper->getConfiguration();
        $searchEngineClient = ClientFactory::create($searchEngineConfig);

        $query = $searchEngineClient->getQuery();

        $queryFields = $queryFields['items'] ?? [];

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
                    'object_type', CmsBlockField::OBJECT_TYPE
                ),
            ],
            [
                new \G4NReact\MsCatalog\Document\Field('identifier',
                    $identifiers,
                    \G4NReact\MsCatalog\Document\Field::FIELD_TYPE_STRING,
                    true,
                    false
                )
            ],
            [
                $this->queryHelper->getFieldByCmsBlockColumnName(
                    'is_active', true
                )
            ]
        ]);

        /** @var \G4NReact\MsCatalog\ResponseInterface $cmsBlockResult */
        $cmsBlockResult = $query->getResponse();

        $cmsBlocks = [];
        if ($cmsBlockResult->getNumFound()) {
            foreach ($cmsBlockResult->getDocumentsCollection() as $cmsBlock) {
                $cmsBlocks[] = $this->prepareDocumentResult($cmsBlock, $queryFields, 'mscmsblock');
            }
        }

        return ['items' => $cmsBlocks ?: []];
    }
}
