<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Observer\Resolver\Products;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Class ResultReturnBefore
 * @package G4NReact\MsCatalogMagento2GraphQl\Observer\Resolver\Products
 */
class ResultReturnBefore implements ObserverInterface
{
    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        $resultObject = $observer->getResult();
        if (!$resultObject) {
            return;
        }

        $result = $resultObject->getResult();

        if (!$result || !is_array($result) || !isset($result['items']) || !is_array($result['items'])) {
            return;
        }

        foreach ($result['items'] as $key => $product) {
            if (is_array($product) && isset($product['media_gallery'])) {
                $mediaGalleryJson = $product['media_gallery'];
                $mediaGallery = json_decode($mediaGalleryJson);
                if ($mediaGallery) {
                    $product['media_gallery'] = $mediaGallery;
                    $result['items'][$key] = $product;
                }
            }
        }

        $resultObject->setData('result', $result);
    }
}
