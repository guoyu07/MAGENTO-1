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
 * Copernica config helper
 *
 * @todo check if this class can be better...
 */
class Copernica_Integration_Helper_Config extends Mage_Core_Helper_Abstract
{
    /**
     *  Define a prefix used for the config
     *  @name CONFIG_BASE   a prefix
     */
    const CONFIG_BASE = 'integration/';

    /**
     * Holds a list of previously requested key names
     * @var array
     */
    protected static $_keyNameCache = array();

    /**
     * List of already requested or used config entries
     *
     * @var array
     */
    protected static $_configEntryCache = array();

    /**
     * Magic method to get configurations from the database
     * @param  string $method
     * @param  array  $params
     * @return string
     */
    public function __call($method, $params)
    {
        switch (substr($method, 0, 3)) {

            case 'get':
                $key = $this->_toKeyName(substr($method, 3));
                return $this->_getCustomConfig($key);

                break;

            case 'set':
                // Check if the first parameter is set
                if (!isset($params) || !isset($params[0])) return false;

                $key = $this->_toKeyName(substr($method, 3));
                $this->_setCustomConfig($key, $params[0]);

                break;

            case 'has':
                $key = $this->_toKeyName(substr($method, 3));
                return $this->_hasCustomConfig($key);

                break;

            case 'uns':
                $key   = $this->_toKeyName(substr($method, 3));
                $model = $this->_getModel($key);

                if ($model !== false) {
                    try {
                        $model->delete();
                        unset(self::$_configEntryCache[$key]);
                    } catch (Exception $e) {
                        Mage::log('integration Config: ' . $e->getMessage());
                    }
                }

                break;
        }

        return false;
    }

    /**
     * Tries to get config value from custom config table
     * @param  string $key
     * @return string
     */
    protected function _getCustomConfig($key)
    {
        if (isset(self::$_configEntryCache[$key])) {
            return self::$_configEntryCache[$key];
        }

        $model = $this->_getModel($key);
        if ($model !== false) {
            return $model->getValue();
        }

        return null;
    }

    /**
     * Sets a config entry in the custom config tab
     * @param string $key
     * @param string $value
     */
    protected function _setCustomConfig($key, $value)
    {
        $model = $this->_getModel($key);

        if ($model === false) {
            $model = Mage::getModel('integration/config');
        }

        try {
            $model->setKeyName($key);
            $model->setValue($value);
            $model->save();

            self::$_configEntryCache[$key] = $model->getValue();

            return $model->getValue();

        } catch (Exception $e) {
            Mage::log('integration Config: ' . $e->getMessage());
        }
    }

    /**
     * Checks if an entry exists in the custom config table
     * @param  string $key
     * @return boolean
     */
    protected function _hasCustomConfig($key)
    {
        // check if it is already in the cache of earlier items or if it exists in the model
        return (!empty(self::$_configEntryCache[$key]) || ($this->_getModel($key) !== false));
    }

    /**
     * Loads the requested model config object if possible
     *
     * @param string $key
     * @return Copernica_Integration_Model_Config
     */
    protected function _getModel($key)
    {
        $model = Mage::getModel('integration/config')->loadByKey($key);

        if ($model && $model->getId()) {
            self::$_configEntryCache[$key] = $model->getValue();
            return $model;
        }

        return false;
    }

    /**
     * Prepends uppercase characters with underscores and lowers
     * the whole string
     *
     * @param string $name
     * @return string
     */
    protected function _toKeyName($name)
    {
        if (isset(self::$_keyNameCache[$name])) {
            return self::$_keyNameCache[$name];
        }

        $result = strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $name));

        self::$_keyNameCache[$name] = $result;
        return $result;
    }

    /**
     *  Get the config item from the custom config table, otherwise from
     *  the basic magento component.
     *  @param  string  $name   Name of the config parameter
     */
    protected function _getConfig($name)
    {
        return $this->_getCustomConfig($name);
    }

    /**
     *  Set the config item from the basic magento component
     *  @param  string  $name   Name of the config parameter
     *  @param  string  $value  Value that should be stored in the config
     */
    protected function _setConfig($name, $value)
    {
        // is this value new the same as the existing value
        if ($value === $this->_getConfig($name)) return;

        // Store the value in the custom config
        $this->_setCustomConfig($name, $value);

        // some config items are not that interesting
        if (in_array($name, array(
            'customer_progress_status',
            'order_progress_status',
            'subscription_progress_status',
            'cronjob_starttime',
            'cronjob_endtime',
            'cronjob_processedtasks',
        ))) return;

        // We have to reset the progress status
        $this->setCustomerProgressStatus('0');
        $this->setOrderProgressStatus('0');
        $this->setSubscriptionProgressStatus('0');
    }

    /**
     *  Check if store is enabled.
     *  @param  int
     *  @return bool
     */
    public function isEnabledStore($storeId)
    {
        // get enabled stores
        $stores = @unserialize($this->_getConfig('enabled_stores'));

        // if we don't have an array we will return true
        if (!is_array($stores)) return true;

        // check if store Id is in enabled array
        return in_array($storeId, $stores);
    }

    /**
     *  Set stores. Pass null to disable store filtering.
     *  @param  array|null
     *  @return Copernica_Integration_Helper_Config
     */
    public function setEnabledStores(array $collectionOfStoresId = null)
    {
        // set stores
        $this->_setConfig('enabled_stores', serialize($collectionOfStoresId));
        return $this;
    }

    /**
     *  Get list of enabled stores
     *  @return array|null
     */
    public function getEnabledStores()
    {
        return unserialize($this->_getConfig('enabled_stores'));
    }

    /**
     *  Get the progress status for customers
     *  This is the created timestamp of the most recent customer which has
     *  been queued for synchronisation
     *  @return datetime
     */
    public function getCustomerProgressStatus()
    {
        return $this->_getConfig('customer_progress_status');
    }

    /**
     *  Set the progress status for customers
     *  This is the created timestamp of the most recent customer which has
     *  been queued for synchronisation
     *  @param datetime
     */
    public function setCustomerProgressStatus($value)
    {
        $this->_setConfig('customer_progress_status', $value);
        return $this;
    }

    /**
     *  Get the progress status for orders
     *  This is the created timestamp of the most recent order which has
     *  been queued for synchronisation
     *  @return datetime
     */
    public function getOrderProgressStatus()
    {
        return $this->_getConfig('order_progress_status');
    }

    /**
     *  Set the progress status for orders
     *  This is the created timestamp of the most recent order which has
     *  been queued for synchronisation
     *  @param datetime
     */
    public function setOrderProgressStatus($value)
    {
        $this->_setConfig('order_progress_status', $value);
        return $this;
    }

    /**
     *  Get the progress status for subscriptions
     *  This is the created timestamp of the most recent subscription which has
     *  been queued for synchronisation
     *  @return datetime
     */
    public function getSubscriptionProgressStatus()
    {
        return $this->_getConfig('subscription_progress_status');
    }

    /**
     *  Set the progress status for subscriptions
     *  This is the created timestamp of the most recent subscription which has
     *  been queued for synchronisation
     *  @param datetime
     */
    public function setSubscriptionProgressStatus($value)
    {
        $this->_setConfig('subscription_progress_status', $value);
        return $this;
    }

    /**
     *  Get the last start time of the cronjob.
     *  @return datetime
     */
    public function getLastStartTimeCronjob()
    {
        return $this->_getConfig('cronjob_starttime');
    }

    /**
     *  Set the last start time of the cronjob.
     *  @param  datetime
     */
    public function setLastStartTimeCronjob($value)
    {
        $this->_setConfig('cronjob_starttime', $value);
        return $this;
    }

    /**
     *  Get the last end time of the cronjob.
     *  @return datetime
     */
    public function getLastEndTimeCronjob()
    {
        return $this->_getConfig('cronjob_endtime');
    }

    /**
     *  Set the last end time of the cronjob.
     *  @param  datetime
     */
    public function setLastEndTimeCronjob($value)
    {
        $this->_setConfig('cronjob_endtime', $value, true);
        return $this;
    }

    /**
     *  Get the number of processed records of the last cronjob run.
     *  @return integer
     */
    public function getLastCronjobProcessedTasks()
    {
        return (int)$this->_getConfig('cronjob_processedtasks');
    }

    /**
     *  Set the last end time of the cronjob.
     *  @param  integer
     */
    public function setLastCronjobProcessedTasks($value)
    {
        $this->_setConfig('cronjob_processedtasks', $value);
        return $this;
    }
}
