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
 *  Sometimes integration has to setup some kind of custom configuration that
 *  that should not be accessible via Magento standard configuration system.
 *  For such occassions this helper class was created.
 *
 *  For easier use it's possible to make actions on properties using autogenerated
 *  methods. To do so, use following pattern for method calling:
 *
 *      [action][property]()
 *
 *  where [action] is either "get", "set", "has", "uns" and property is property
 *  name written in camel case convention (must start with uppercase).
 *
 *  It is also possible to make such actions on property using public ::get(),
 *  ::set(), ::has(), ::remove() methods. Thus with such, use property name has to
 *  be in undescrore convention.
 *
 *  Usage:
 *
 *      // get the instance
 *      $config = Mage::getHelper('integration/config');
 *
 *      // set some property value
 *      $config->set('some_property', 'value');
 *      // or
 *      $config->setSomeProperty('value');
 *
 *      // get value of 'some_property'
 *      $value = $config->getSomeProperty();
 *
 *  Known configuration names:
 *  
 *      -   sync_progress   This option is used to store current amount of processed
 *                          entities within start_sync task
 *
 *      -   sync_total      This option is used to store total amount of to be processed
 *                          entities within start_sync task
 *
 *      -   sync_state      This option stores serialized data about start_sync
 *                          task.
 *
 */
class Copernica_Integration_Helper_Config extends Mage_Core_Helper_Abstract
{
    /**
     *  Some of config properties can be used quite frequently. To save some
     *  magento and db time we will implement a simple cachce array that will 
     *  store recently used entries.
     *
     *  @var    array
     */
    private static $cache = array();

    /**
     *  Some of methods are generated in fly and they depend on certain config 
     *  state. We can handle them thanks to this magic method override.
     *
     *  get* methods will return the actual value of property or NULL if property
     *  is not set at all.
     *
     *  set* and uns* methods will return config instance (as chaining instance)
     *
     *  has* will always return true (when property is set) or false (when property
     *  is not set)
     *
     *  @param  string  The method name
     *  @param  mixed   The parametres
     *  @return mixed   It depends on what kind of actions we are taking inside.
     */
    public function __call($method, $params)
    {
        /**
         *  We will serve autogenerated methods that fall into overall magento
         *  naming convention for properties. Basically they should contain 
         *  a 3 characters long action prefix and a uppercased property name.
         *  There are 4 types of actions that can be done on properties that we
         *  can handle here: setting (set), getting (get), checking (has) and 
         *  removing (uns). 
         */
        $action = substr($method, 0, 3);
        $property = substr($method, 3);

        /**
         *  When calling methods we are using camel case convention, but we are 
         *  using undescore convention for property names. So, we have to convert
         *  current property name from camel case to underscore case.
         */
        $property = strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $property));

        // decide what to do depending on action prefix
        switch ($action)
        {
            /** 
             *  For checking if property exists we just have to call internal 
             *  method and return the output.
             */
            case 'has':
                return $this->has($property);
            
            /**
             *  For getting property value we just have to call internal method
             *  and reutrn the output.
             */
            case 'get': 
                return $this->get($property);

            /**
             *  For setting action we have to check if we have one and only 
             *  parameter also supplied. If we have them we can proceed normaly.
             *  When we are missing parameter we will use null as default parameter.
             */
            case 'set':
                $parameter = (!isset($params) || count($params) < 1) ? null : $params[0];
                $this->set($property, $params[0]);

                // allow chaining
                return $this;

            /** 
             *  For removing property we just have to call internal method and 
             *  allow chainig (since no output is required).
             */
            case 'uns':
                $this->remove($property);

                // allow chaining
                return $this;
        }

        /**
         *  When some kind of undefined method is called we will just respond 
         *  with null value as universal 'whaaat?'.
         */
        return null;
    }

    /**
     *  Get property value by name.
     *
     *  @param  sting
     *  @return mixed
     */
    public function get($property)
    {
        // if we have model entry inside cache we can just return value from cached instance
        if (array_key_exists($property, self::$cache)) return self::$cache[$property]->getValue();

        // fetch config model by property key
        $model = Mage::getModel('integration/config')->load($property, 'key_name');

        // if we don't have a proper model we will just return null as it's value
        if (!$model->getID()) return null;

        // store model inside internal cache
        self::$cache[$property] = $model;

        // return config value
        return $model->getValue();
    }

    /**
     *  Set property value by name
     *
     *  @param  string  The property name
     *  @param  mixed   The property value
     *  @return Copernica_Integration_Helper_Config
     */
    public function set($property, $value = null)
    {
        /**
         *  Load model for given property name. We prefere to load model from 
         *  cached array of models, but if there is no model that would match
         *  property name we will just try to ask for model with given property 
         *  name.
         */
        $model = array_key_exists($property, self::$cache) ? self::$cache[$property] : Mage::getModel('integration/config')->load($property, 'key_name');

        // update model value and save it
        $model->setValue($value);
        $model->setKeyName($property);
        $model->save();

        // create/updace cache entry
        self::$cache[$property] = $model;

        // allow chaining
        return $this;
    }

    /**
     *  Checking property by name.
     *
     *  @param  string  The property name
     *  @return boolean Is property set
     */
    public function has($property)
    {
        /**
         *  If we have property inside cache we are sure that we have such 
         *  property set. So no further checking is required.
         */
        if (array_key_exists($property, self::$cache)) return true;

        // we have to ask magento for a model by given name
        $model = Mage::getModel('integration/config')->load($property, 'key_name');

        // if model has ID we can tell tha it is in database so it's set
        if ($model->getId()) return true;

        // well model is not set at all
        return false;
    }

    /**
     *  Removing property by name.
     *  
     *  @param  string  The property name
     *  @return Copernica_Integration_Helper_Config
     */
    public function remove($property)
    {
        /**
         *  Load model for given property name. We prefere to load model from 
         *  cached array of models, but if there is no model that would match
         *  property name we will just try to ask for model with given property 
         *  name.
         */
        $model = array_key_exists($property, self::$cache) ? self::$cache[$property] : Mage::getModel('integration/config')->load($property, 'key_name');

        // if model has Id then it is stored inside database, so we should remove it
        if ($model->getId()) $model->delete(); 

        // unset model from cache
        if (array_key_exists($property, self::$cache)) unset(self::$cache[$property]);

        // allow chaining
        return $this;
    }
}
