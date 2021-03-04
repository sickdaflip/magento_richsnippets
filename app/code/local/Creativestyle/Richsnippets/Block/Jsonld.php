<?php

class Creativestyle_Richsnippets_Block_Jsonld extends Mage_Core_Block_Template
{

    public function getProduct()
    {
        $product = Mage::registry('current_product');
        return ($product && $product->getEntityId()) ? $product : false;
    }

    public function getAttributeValue($attr)
    {
        $value = null;
        $product = $this->getProduct();
        if ($product) {
            $type = $product->getResource()->getAttribute($attr)->getFrontendInput();

            if ($type == 'text' || $type == 'textarea') {
                $value = $product->getData($attr);
            } elseif ($type == 'select') {
                $value = $product->getAttributeText($attr) ? $product->getAttributeText($attr) : '';
            }
        }

        return $value;
    }

    public function getStructuredData()
    {
        // get product
        $product = $this->getProduct();

        // check if $product exists
        if ($product) {
            $categoryName = Mage::registry('current_category') ? Mage::registry('current_category')->getName() : '';
            $productId = $product->getEntityId();
            $storeId = Mage::app()->getStore()->getId();
            $currencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();

            $json = array(
                'availability' => $product->isAvailable() ? 'http://schema.org/InStock' : 'http://schema.org/OutOfStock',
                'category' => $categoryName
            );

            // check if reviews are enabled in extension's backend configuration
            $review = Mage::getStoreConfig('richsnippets/general/review');
            if ($review) {
                $reviewSummary = Mage::getModel('review/review/summary');
                $ratingData = Mage::getModel('review/review_summary')->setStoreId($storeId)->load($productId);

                // get reviews collection
                $reviews = Mage::getModel('review/review')
                    ->getCollection()
                    ->addStoreFilter($storeId)
                    ->addStatusFilter(1)
                    ->addFieldToFilter('entity_id', 1)
                    ->addFieldToFilter('entity_pk_value', $productId)
                    ->setDateOrder()
                    ->addRateVotes()
                    ->getItems();

                $reviewData = array();
                if (count($reviews) > 0) {
                    foreach ($reviews as $r) {
                        $ratings = array();
                        foreach ($r->getRatingVotes() as $vote) {
                            $ratings[] = $vote->getPercent();
                        }

                        $avg = array_sum($ratings) / count($ratings);
                        $avg = number_format(floor(($avg / 20) * 2) / 2, 1); // average rating (1-5 range)

                        $datePublished = explode(' ', $r->getCreatedAt());

                        // another "mini-array" with schema data
                        $reviewData[] = array(
                            '@type' => 'Review',
                            'author' => $this->htmlEscape($r->getNickname()),
                            'datePublished' => str_replace('/', '-', $datePublished[0]),
                            'name' => $this->htmlEscape($r->getTitle()),
                            'reviewBody' => nl2br($this->escapeHtml($r->getDetail())),
                            'reviewRating' => array(
                                '@type' => 'Rating',
                                'ratingValue' => $avg
                            )
                        );
                    }
                }

                // let's put review data into $json array

                $json['reviewCount'] = $reviewSummary->getTotalReviews($product->getId(), true);

                $json['ratingValue'] = number_format(floor(($ratingData['rating_summary'] / 20) * 2) / 2, 1); // average rating (1-5 range)
                $json['review'] = $reviewData;
            }

            //use Desc if Shortdesc not work
            if ($product->getShortDescription()) {
                $descsnippet = html_entity_decode(strip_tags($product->getShortDescription()));
            } else {
                $descsnippet = Mage::helper('core/string')->substr(html_entity_decode(strip_tags($product->getDescription())), 0, 165);
            }

            // Final array with all basic product data --->
            $tierPrices = array();
            $tierInfo = $product->getTierPrice();

            if (is_null($tierInfo)) {
                $attribute = $product->getResource()->getAttribute('tier_price');
                if ($attribute) {
                    $attribute->getBackend()->afterLoad($product);
                    $tierInfo = $product->getData('tier_price');
                }
            }

            foreach ($tierInfo as $tierPrice) {
                array_push($tierPrices, $tierPrice['price']);
            }
            if ($tierPrices) {
                $minTier = min($tierPrices);
            } else {
                $minTier = false;
            }

            $priceValidUntil = date("c", strtotime("+1 day"));

            $price_raw = Mage::helper('tax')->getPrice($product, $product->getFinalPrice(), true, null, null, null, 5, null, true);

            $valueAddedTaxIncluded = 'true';

            $data = array(
                '@context' => 'http://schema.org',
                '@type' => 'Product',
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'mpn' => substr(strstr(Mage::getModel('catalog/product')->load($product->getId())->getSku(),"-",false),1),
                'gtin' => $product->getData('gtin'),
                'image' => $product->getImageUrl(),
                'url' => $product->getProductUrl(),
                'description' => $descsnippet, //use Desc if Shortdesc not work
                'weight' => $product->getAttributeText('weight'),
                'brand' => array(
                    '@type' => 'Thing',
                    'name' => $product->getAttributeText('manufacturer')
                ),
                'itemCondition' => $product->getAttributeText('condition'),
                'offers' => array(
                    '@type' => 'Offer',
                    'availability' => $json['availability'],
                    'price' => $price_raw,
                    'priceCurrency' => $currencyCode,
                    'category' => $json['category'],
                    'priceValidUntil' => $priceValidUntil,
                    'url' => $product->getProductUrl(),
                    'PriceSpecification' => array(
                        '@type' => 'PriceSpecification',
                        'priceCurrency' => 'EUR',
                        'valueAddedTaxIncluded' => $valueAddedTaxIncluded,
                        'priceCurrency' => $currencyCode
                    )
                )
            );

            // if reviews enabled - join it to $data array
            if ($review) {
                $data['aggregateRating'] = array(
                    '@type' => 'AggregateRating',
                    'bestRating' => '5',
                    'worstRating' => '0',
                    'ratingValue' => $json['ratingValue'],
                    'reviewCount' => $json['reviewCount']
                );
                $data['review'] = $reviewData;
            }

            // getting all attributes from "Attributes" section of or extension's config area...
            $attributes = Mage::getStoreConfig('richsnippets/attributes');

            // ... and putting them into $data array if they're not empty
            foreach ($attributes AS $key => $value) {
                if ($value) {
                    $data[$key] = $this->getAttributeValue($value);
                }
            }

            // return $data table in JSON format
            return '[' . json_encode($data) . ']';
        }

        return null;
    }
}
