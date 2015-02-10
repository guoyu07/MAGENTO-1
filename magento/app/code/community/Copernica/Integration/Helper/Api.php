<?php
/**
 *  Copernica Marketing Software
 *
 *  NOTICE OF LICENSE
 *
 *  This source file is subject to the Open Software License (OSL 3.0).
 *  It is available through the world-wide-web at this URL:
 *  http://opensource.org/licenses/osl-3.0.php
 *  If you are unable to obtain a copy of the license through the
 *  world-wide-web, please send an email to copernica@support.cream.nl
 *  so we can send you a copy immediately.
 *
 *  DISCLAIMER
 *
 *  Do not edit or add to this file if you wish to upgrade this software
 *  to newer versions in the future. If you wish to customize this module
 *  for your needs please refer to http://www.magento.com/ for more
 *  information.
 *
 *  @category       Copernica
 *  @package        Copernica_Integration
 *  @copyright      Copyright (c) 2011-2012 Copernica & Cream. (http://docs.cream.nl/)
 *  @license        http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  @documentation  public
 */

/**
 *  Coppernica REST API class.
 *  This class holds methods to communicate with Copernica REST API. It's also
 *  a facade for valication and creation classes.
 */
class Copernica_Integration_Helper_Api extends Mage_Core_Helper_Abstract
{
    /**
     *  Request object that handles raw REST requests
     *  @var    Copernica_Integration_Helper_RESTRequest
     */
    protected $request = null;

    /**
     *  Public, standard PHP constructor. Mage_Core_Helper_Abstract is not a child
     *  of Varien_Object, so we want to use good old PHP constructor.
     */
    public function __construct()
    {
        // create the request handler
        $this->request = Mage::helper('integration/RESTRequest');
    }

    /**
     *  Check if this API instance is valid.
     *
     *  @return boolean
     */
    public function check()
    {
        // just check the request
        return $this->request->check();
    }

    /**
     *  Upgrade request token data into access token via REST call.
     *
     *  @param  string  Code that we did get from Copernica authorization page
     *  @param  string  Our landing page for state handling
     *  @return string  The access token or false when we can not upgrade
     */
    public function upgradeRequest($code, $redirectUri)
    {
        // make an upgrade request
        $output = $this->request->get('token', array(
            'client_id'     =>  'fccd12ee5499739753fd12a170998549',
            'client_secret' =>  '8f65b8fd2cd80c6563f973fa3ca18952',
            'code'          =>  $code,
            'redirect_uri'  =>  $redirectUri
        ));

        // check for a valid access tokne
        if (empty($output['access_token'])) return false;

        // update the access token in the request
        $this->request->setAccessToken($output['access_token']);

        // return the new access token
        return $output['access_token'];
    }

    /**
     *  Retrieve information about the account we are linked to
     *
     *  @return map an array with the properties 'id, 'name', 'description' and 'company'
     */
    public function account()
    {
        // retrieve information from the request
        return $this->request->get('identity');
    }

    /**
     *  Store collection of models
     *  
     *  @param  Varien_Data_Collection_Db
     */
    public function storeCollection (Varien_Data_Collection_Db $collection)
    {
        // if we don't have a proper collection or don't have anything inside 
        // collection we can bail out
        if (!is_object($collection) && $collection->count() == 0) return;

        // get resource name of 1st item
        $resourceName = $collection->getFirstItem()->getResourceName();

        // store collection according to resource name
        switch ($resourceName) {
            case 'sales/quote': foreach ($collection as $quote) $this->storeQuote($quote); break;
            case 'sales/quote_item': foreach ($collection as $item) $this->storeQuoteItem($item); break;
            case 'sales/order': foreach ($collection as $order) $this->storeOrder($order); break;
            case 'sales/order_item': foreach ($collection as $item) $this->storeOrderItem($item); break;
            case 'newsletter/subscriber': foreach ($collection as $subscriber) $this->storeSubscriber($subscriber); break;
            case 'core/store': foreach ($collection as $store) $this->storeStore($store); break;

            /** 
             *  Category collection does not load all needed category data. Thus 
             *  we have to realod category object to fetch additional data.
             */
            case 'catalog/category':
                foreach ($collection as $category) 
                {
                    // reaload category
                    $category = Mage::getModel('catalog/category')->load($category->getId());

                    // store reloaded category
                    $this->storeCategory($category);                    
                }
                break;

            /** 
             *  Products collection does load product objects with some of the
             *  needed data, that is why we want to reload product instance
             *  via Mage::getModel() method.
             */
            case 'catalog/product': 
                foreach ($collection as $product) 
                {
                    // reload product
                    $product = Mage::getModel('catalog/product')->load($product->getId());

                    // store reloaded product
                    $this->storeProduct($product); 
                }
                break;

            /** 
             *  Addresses loaded from collections don't contain all needed data.
             *  So, to ensure that we have all needed data we have to force 
             *  Magento to fetch full data set.
             */
            case 'sales/order_address':
            case 'sales/quote_address':
            case 'customer/address': 
                foreach ($collection as $address)
                {
                    // reload address data
                    $address = Mage::getModel($resourceName)->load($address->getId());

                    // store address
                    $this->storeAddress($address);  
                } 

                // we are done here
                break;

            /** 
             *  Customer collection does load customer objects with some of the
             *  needed data, that is why we want to reload customer instance
             *  via Mage::getModel() method.
             */
            case 'customer/customer': 
                foreach ($collection as $customer) 
                {
                    // reload customer data
                    $customer = Mage::getModel('customer/customer')->load($customer->getId());

                    // store customer
                    $this->storeCustomer($customer); 
                }

                // we are done here
                break;
        }
    }

    /**
     *  Register a product with copernica
     *
     *  @param  Mage_Catalog_Model_Product  the product that was added or modified
     */
    public function storeProduct(Mage_Catalog_Model_Product $product)
    {
        // we will need store instance to get the currency code
        $store = Mage::getModel('core/store')->load($product->getStoreId());

        // store the product
        $this->request->put("magento/product/{$product->getId()}", array(
            'sku'           =>  $product->getSku(),
            'name'          =>  $product->getName(),
            'description'   =>  $product->getDescription(),
            'currency'      =>  $store->getCurrentCurrencyCode(),
            'price'         =>  $product->getPrice(),
            'weight'        =>  $product->getWeight(),
            'modified'      =>  $product->getUpdatedAt(),
            'uri'           =>  $product->getProductUrl(),
            'image'         =>  $product->getImageUrl(),
            'categories'    =>  $product->getCategoryIds(),
        ));
    }

    /**
     *  Register a quote with copernica
     *
     *  @param  Mage_Sales_Model_Quote  the quote that was created or modified
     */
    public function storeQuote(Mage_Sales_Model_Quote $quote)
    {
        // check if store is disabled for sync
        if (!Mage::getStoreConfig('copernica_options/apisync/enabled', $quote->getStoreId())) return;

        // get the shipping and billing addresses
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress  = $quote->getBillingAddress();

        // get quote totals
        $totals = $quote->getTotals();

        // store the quote
        $this->request->put("magento/quote/{$quote->getId()}", array(
            'customer'          =>  $quote->getCustomerId(),
            'webstore'          =>  $quote->getStoreId(),
            'shipping_address'  =>  is_null($shippingAddress)   ? null : $shippingAddress->getId(),
            'billing_address'   =>  is_null($billingAddress)    ? null : $billingAddress->getId(),
            'weight'            =>  is_null($shippingAddress)   ? null : $shippingAddress->getWeight(),
            'active'            =>  (bool)$quote->getIsActive(),
            'quantity'          =>  $quote->getItemsQty(),
            'currency'          =>  $quote->getQuoteCurrencyCode(),
            'shipping_cost'     =>  $quote->getShippingAmount(),
            'tax'               =>  isset($totals['tax']) ? $totals['tax']->getValue() : 0,
            'ip_address'        =>  $quote->getRemoteIp(),
            'last_modified'     =>  $quote->getUpdatedAt(),
        ));
    }

    /**
     *  Remove a quote item from copernica
     *
     *  @param  Mage_Sales_Model_Quote_Item the quote item that was removed
     */
    public function removeQuoteItem(Mage_Sales_Model_Quote_Item $item)
    {
        // remove the quote item
        $this->request->delete("magento/quoteitem/{$item->getId()}");
    }

    /**
     *  Register a quote item with copernica
     *
     *  @param  Mage_Sales_Model_Quote_Item the quote item that was created or modified
     */
    public function storeQuoteItem(Mage_Sales_Model_Quote_item $item)
    {
        /**
         *  Load the accompanying quote by id, since the getQuote method
         *  seems to be severely borken in some magento versions
         *  Quote is a store entity. And just cause of that magento doing funky
         *  stuff when fetching quote just by id. To fetch quote with any kind 
         *  of useful data we have to explicitly say to magento that we want a 
         *  quote without store.
         */
        $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($item->getQuoteId());

        // check if store is disabled for sync
        if (!Mage::getStoreConfig('copernica_options/apisync/enabled', $quote->getStoreId())) return;

        // item-quote relation is super broken
        $item->setQuote($quote);

        /**
         *  Something about magento address handling. It's possible to set 
         *  shipping address for each quote item to completely different places.
         *  The 'multi shipping'. Really nice feature. Thus, you can not ask
         *  the quote item to where it will be shipped. Instead you have to 
         *  ask the quote address to where item will be shipped (by ::getItemByQuoteItemId())
         *  and then you will be given a address object or false value when 
         *  item does not have any special destination. 
         *  For regular person, false value would mean that item does not have 
         *  a shipping destination.
         */

        // get quote item shipping address
        $quoteItemShippingAddress = $quote->getShippingAddress()->getItemByQuoteItemId($item->getId());

        // store the quote item
        $this->request->put("magento/quoteitem/{$item->getId()}", array(
            'quote'     =>  $item->getQuoteId(),
            'product'   =>  $item->getProductId(),
            'quantity'  =>  $item->getQty(),
            'price'     =>  $item->getPrice(),
            'currency'  =>  $quote->getQuoteCurrencyCode(),
            'weight'    =>  $item->getWeight(),
            'address'   =>  is_object($quoteItemShippingAddress) ? $quoteItemShippingAddress->getAddress()->getId() : null,
        ));
    }

    /**
     *  Register an order with copernica
     *
     *  @param  Mage_Sales_Model_Order  the order that was created or modified
     */
    public function storeOrder(Mage_Sales_Model_Order $order)
    {
        // check if store is disabled for sync
        if (!Mage::getStoreConfig('copernica_options/apisync/enabled', $order->getStoreId())) return;

        // get the shipping and billing addresses
        $shippingAddress = $order->getShippingAddress();
        $billingAddress  = $order->getBillingAddress();

        // determine the gender of the customer
        $gender = strtolower(Mage::getResourceSingleton('customer/customer')->getAttribute('gender')->getSource()->getOptionText($order->getCustomerGender()));

        // if we do not get a gender, something went wrong (or we don't know the gender)
        if (empty($gender)) $gender = null;

        // store the quote
        $this->request->put("magento/order/{$order->getId()}", array(
            'quote'                 =>  $order->getQuoteId(),
            'customer'              =>  $order->getCustomerId(),
            'webstore'              =>  $order->getStoreId(),
            'shipping_address'      =>  is_object($shippingAddress)   ? $shippingAddress->getId()   : null,
            'billing_address'       =>  is_object($billingAddress)    ? $billingAddress->getId()    : null,
            'state'                 =>  $order->getState(),
            'status'                =>  $order->getStatus(),
            'weight'                =>  $order->getWeight(),
            'quantity'              =>  $order->getTotalQtyOrdered(),
            'currency'              =>  $order->getOrderCurrencyCode(),
            'shipping_cost'         =>  $order->getShippingAmount(),
            'tax'                   =>  $order->getTaxAmount(),
            'ip_address'            =>  $order->getRemoteIp(),
            'customer_gender'       =>  $gender,
            'customer_groupname'    =>  $order->getCustomerGroupname(),
            'customer_subscription' =>  $order->getCustomerSubscription(),
            'customer_email'        =>  $order->getCustomerEmail(),
            'customer_firstname'    =>  $order->getCustomerFirstname(),
            'customer_middlename'   =>  $order->getCustomerMiddlename(),
            'customer_prefix'       =>  $order->getCustomerPrefix(),
            'customer_lastname'     =>  $order->getCustomerLastname(),
        ));
    }

    /**
     *  Register an order item with copernica
     *
     *  @param  Mage_Sales_Model_Order_item the item that was created or modified
     */
    public function storeOrderItem(Mage_Sales_Model_Order_Item $item)
    {
        // check if store is disabled for sync
        if (!Mage::getStoreConfig('copernica_options/apisync/enabled', Mage::getModel('sales/order')->load($item->getOrderId())->getStoreId())) return;

        // store the order item
        $this->request->put("magento/orderitem/{$item->getId()}", array(
            'order'     =>  $item->getOrderId(),
            'product'   =>  $item->getProductId(),
            'quantity'  =>  $item->getData('qty_ordered'),
            'price'     =>  $item->getPrice(),
            'currency'  =>  $item->getOrder()->getOrderCurrencyCode(),
            'weight'    =>  $item->getWeight(),
        ));
    }

    /**
     *  Helper method to get subscription status of a subscriber
     *  @param  Mage_Newsletter_Model_Subscriber
     *  @return string
     */
    private function subscriptionStatus(Mage_Newsletter_Model_Subscriber $subscriber)
    {
        switch ($subscriber->getStatus())
        {
            case Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED:   return 'subscribed';
            case Mage_Newsletter_Model_Subscriber::STATUS_NOT_ACTIVE:   return 'not active';
            case Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED: return 'unsubscribed';
            case Mage_Newsletter_Model_Subscriber::STATUS_UNCONFIRMED:  return 'unconfirmed';
            default:                                                    return 'unknown';
        }
    }

    /**
     *  Register a newsletter subscriber with copernica
     *
     *  @param  Mage_Newsletter_Model_Subscriber    the subscriber that was added or modified
     */
    public function storeSubscriber(Mage_Newsletter_Model_Subscriber $subscriber)
    {
        // check if store is disabled for sync
        if (!Mage::getStoreConfig('copernica_options/apisync/enabled', $subscriber->getStoreId())) return;

        // store the subscriber
        $this->request->put("magento/subscriber/{$subscriber->getId()}", array(
            'customer'  =>  $subscriber->getCustomerId(),
            'email'     =>  $subscriber->getEmail(),
            'modified'  =>  $subscriber->getChangeStatusAt(),
            'status'    =>  $this->subscriptionStatus($subscriber),
            'webstore'  =>  $subscriber->getStoreId(),
        ));
    }

    /**
     *  Remove a newsletter subscriber from copernica
     *
     *  @param  Mage_Newsletter_Model_Subscriber    the subscriber that was removed
     */
    public function removeSubscriber(Mage_Newsletter_Model_Subscriber $subscriber)
    {
        // remove the quote
        $this->request->delete("magento/subscriber/{$subscriber->getId()}");
    }

    /**
     *  Store a customer in copernica
     *
     *  @param  Mage_Customer_Model_Customer    the customer that was added or modified
     */
    public function storeCustomer(Mage_Customer_Model_Customer $customer)
    {
        // check if store is disabled for sync
        if (!Mage::getStoreConfig('copernica_options/apisync/enabled', $customer->getStoreId())) return;

        // determine the gender of the customer
        $gender = strtolower(Mage::getResourceSingleton('customer/customer')->getAttribute('gender')->getSource()->getOptionText($customer->getGender()));

        // if we do not get a gender something went wrong (or we don't know the gender)
        if (empty($gender)) $gender = null;

        // store the customer
        $this->request->put("magento/customer/{$customer->getId()}", array(
            'webstore'      =>  $customer->getStoreId(),
            'firstname'     =>  $customer->getFirstname(),
            'prefix'        =>  $customer->getPrefix(),
            'middlename'    =>  $customer->getMiddlename(),
            'lastname'      =>  $customer->getLastname(),
            'email'         =>  $customer->getEmail(),
            'gender'        =>  $gender,
        ));
    }

    /**
     *  Remove a customer from copernica
     *
     *  @param  Mage_Customer_Model_Customer    the customer that was removed
     */
    public function removeCustomer(Mage_Customer_Model_Customer $customer)
    {
        // remove the customer
        $this->request->delete("magento/customer/{$customer->getId()}");
    }

    /**
     *  Store an address in copernica
     *
     *  @param  Mage_Customer_Model_Address_Abstract the address that was added or modified
     */
    public function storeAddress(Mage_Customer_Model_Address_Abstract $address)
    {
        /**
         *  Magento has a little mess with address handling. Basically there 
         *  can be several types of address that will have common structure. 
         *  Semantically they mean same this: a real place in the world. It would
         *  be wise to put them inside one table and have only one class that will
         *  describe such basic thing. Magento core team decided to separate 
         *  such entities and make separata ID sequences for customer, order and
         *  quote address (maybe there are more, but they don't concern us right now),
         *  making whole address handling very ambiguous.
         *  To make things easier we will limit ourselfs to customer, order and quote 
         *  address and assign them a 'type' that will describe from what kind
         *  of magento address copernica address came. 
         *  If we will encounter any other type of address we will just ignore it
         *  it since we don't have any means ofhadnling such.
         *
         *  And since customer, order, quote flavors of common address classes
         *  are pretty much separate they have different interfaces for fetching 
         *  common data like customer id or shipping and billing flags. Thus we
         *  have to parse them in correct manner.
         */ 
        if ($address instanceof Mage_Customer_Model_Address)
        {
            // get customer instance
            $customer = $address->getCustomer();

            // check if store is disabled for sync
            if (!Mage::getStoreConfig('copernica_options/apisync/enabled', $customer->getStoreId())) return;

            // set address type, customer, billing and shipping flag
            $metaData = array (
                'type'              => 'customer',
                'billingAddress'    => $customer->getDefaultBilling() == $address->getId(),
                'deliveryAddress'   => $customer->getDefaultShipping() == $address->getId(),
                'customer'          => $customer->getId(),
            );
        }
        else if ($address instanceof Mage_Sales_Model_Order_Address)
        {
            // get order instance
            $order = $address->getOrder();

            // check if store is disabled for sync
            if (!Mage::getStoreConfig('copernica_options/apisync/enabled', $order->getStoreId())) return;

            // set address type, customer, billing and shipping flag
            $metaData = array( 
                'type'              => 'order',
                'billingAddress'    => $order->getData('billing_address_id') == $address->getId(),
                'deliveryAddress'   => $order->getData('shipping_address_id') == $address->getId(),
                'customer'          => $order->getData('customer_id'),
            );  
        } 
        else if ($address instanceof Mage_Sales_Model_Quote_Address)
        {
            // get quote instance
            $quote = $address->getQuote();

            // check if store is disabled for sync
            if (!Mage::getStoreConfig('copernica_options/apisync/enabled', $quote->getStoreId())) return;

            // set address type, customer, billing and shipping flag
            $metaData = array(
                'type'              => 'quote',
                'billingAddress'    => $address->getData('address_type') == 'billing',
                'deliveryAddress'   => $address->getData('address_type') == 'shipping',
                'customer'          => $address->getData('customer_id')
            );  
        } 

        // we have some unknown address type. We will not do anything good with it
        else return;

        // store the address
        $this->request->put("magento/address/{$address->getId()}", array_merge( $metaData, array(
            'country'           =>  (string)$address->getCountry(),
            'street'            =>  (string)$address->getStreetFull(),
            'city'              =>  (string)$address->getCity(),
            'zipcode'           =>  (string)$address->getPostcode(),
            'state'             =>  (string)$address->getRegion(),
            'phone'             =>  (string)$address->getTelephone(),
            'fax'               =>  (string)$address->getFax(),
            'company'           =>  (string)$address->getCompany(),
        )));
    }

    /**
     *  Remove magento address from copernica platform
     *  @param  Mage_Customer_Model_Address_Abstract
     */
    public function removeAddress(Mage_Customer_Model_Address_Abstract $address)
    {
        /**
         *  Similar to store action we have to detect what kind of address we are
         *  dealing with and add additional type parameter.
         */
        if ($address instanceof Mage_Customer_Model_Address) $type = 'customer';
        else if ($address instanceof Mage_Sales_Model_Order_Address) $type = 'order';
        else if ($address instanceof Mage_Sales_Model_Quote_Address) $type = 'quote';
        else return;

        // remove address
        $this->request->delete("magento/address", array( 'ID' => $address->getId(), 'type' => $type));
    }

    /**
     *  Store an store in copernica
     *  
     *  @param  Mage_Core_Model_Store
     */
    public function storeStore(Mage_Core_Model_Store $store)
    {
        // get store website
        $website = $store->getWebsite();

        // get store group
        $group = $store->getGroup();

        // store the store
        $this->request->put("magento/store/{$store->getId()}", array(
            'name'          => $store->getName(),
            'websiteId'     => $website->getId(),
            'websiteName'   => $website->getName(),
            'groupId'       => $group->getId(),
            'groupName'     => $group->getName(),
            'rootCategory'  => $store->getRootCategoryId(),
        ));
    }

    /**
     *  Store magento category in copernica
     *  @param  Mage_Catalog_Model_Category
     */
    public function storeCategory(Mage_Catalog_Model_Category $category)
    {
        $this->request->put("magento/category/{$category->getId()}", array(
            'name'      =>  $category->getName(),
            'created'   =>  $category->getCreatedAt(),
            'modified'  =>  $category->getUpdatedAt(),
            'parent'    =>  $category->getParentCategory()->getId(),
        ));
    }

    /**
     *  Remove magento category in copernica
     *  @param Mage_Catalog_Model_Category
     */
    public function removeCategory(Mage_Catalog_Model_Category $category)
    {
        $this->request->delete("magento/category/{$category->getId()}");
    }
}
