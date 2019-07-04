<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalogMagento2GraphQl\Model\Resolver\DataProvider\Attribute as AttributeDataProvider;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Class Attribute
 * @package G4NReact\MsCatalogMagento2GraphQl\Model\Resolver
 */
class Attribute implements ResolverInterface
{
    /**
     * @var AttributeDataProvider
     */
    protected $attributeDataProvider;

    /**
     * Attribute constructor
     * @param AttributeDataProvider $attributeDataProvider
     */
    public function __construct(
        AttributeDataProvider $attributeDataProvider
    ) {
        $this->attributeDataProvider = $attributeDataProvider;
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
        if (!isset($args['attributeCodes'])) {
            throw new GraphQlInputException(__('Attribute codes should be specified'));
        }

        $data['attributes'] = [];

        try {
            $attributes = $this->attributeDataProvider->getData($args);
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__($e->getMessage()), $e);
        }

        foreach ($args['attributeCodes'] as $attributeCode) {
            $data['attributes'][] = [
                'attribute_code'    => $attributeCode,
                'attribute_id'      => (int)($attributes[$attributeCode]['attribute_id'] ?? 0),
                'attribute_label'   => (string)($attributes[$attributeCode]['frontend_label'] ?? ''),
                'backend_type'      => (string)($attributes[$attributeCode]['backend_type'] ?? ''),
                'frontend_input'    => (string)($attributes[$attributeCode]['frontend_input'] ?? ''),
                'attribute_type'    => (string)($attributes[$attributeCode]['attribute_type'] ?? ''),
                'attribute_options' => $attributes[$attributeCode]['attribute_options'] ?? [],
                'position'          => (int)($attributes[$attributeCode]['position'] ?? 0),
            ];
        }

        return $data;
    }
}
