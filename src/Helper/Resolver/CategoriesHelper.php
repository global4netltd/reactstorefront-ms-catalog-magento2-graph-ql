<?php
namespace G4NReact\MsCatalogMagento2GraphQl\Helper\Resolver;

/**
 * Class CategoriesHelper
 * @package G4NReact\MsCatalogMagento2GraphQl\Helper\Resolver
 */
class CategoriesHelper
{

    /**
     * @param array $categories
     * @param int $parentId
     * @param $childCategory
     * @param int $maxLevel
     * @return array
     */
    public function addChildToCategories(array &$categories, int $parentId, $childCategory, int $maxLevel = 2)
    {
        if($maxLevel !== 0 && isset($childCategory['level']) && $childCategory['level'] > $maxLevel){
            return $categories;
        }
        if (isset($categories[$parentId])) {
            $categories[$parentId]['children'][] = $childCategory;
            return $categories;
        }
        foreach ($categories as &$category) {
            if (isset($category['id']) && (int) $category['id'] === $parentId) {
                $category['children'][] = $childCategory;
                break;
            }
            if (isset($category['children']) && is_array($category['children'])) {
                $category['children'] = $this
                    ->addChildToCategories($category['children'], $parentId, $childCategory, $maxLevel);
                return $categories;
            }
        }

        return $categories;
    }
}