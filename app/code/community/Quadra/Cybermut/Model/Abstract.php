<?php

/**
 * 1997-2016 Quadra Informatique
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0) that is available
 * through the world-wide-web at this URL: http://www.opensource.org/licenses/OSL-3.0
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to modules@quadra-informatique.fr so we can send you a copy immediately.
 *
 * @author Quadra Informatique
 * @copyright 1997-2016 Quadra Informatique
 * @license http://www.opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
abstract class Quadra_Cybermut_Model_Abstract extends Mage_Payment_Model_Method_Abstract
{

    protected $_formBlockType = 'cybermut/form';

    // Cybermut return codes of payment
    const RETURN_CODE_ACCEPTED = 'paiement';
    const RETURN_CODE_TEST_ACCEPTED = 'payetest';
    const RETURN_CODE_ERROR = 'Annulation';

    /**
     * Payment Method features
     * @var bool
     */
    protected $_canAuthorize                = true;
    protected $_canCapture                  = true;

    // Order instance
    protected $_order = null;

    /**
     *  Return CyberMut protocol version
     *
     *  @param    none
     *  @return	  string Protocol version
     */
    protected function getVersion()
    {
        if (!$version = $this->getConfigData('version')) {
            $version = '1.2open';
        }

        return $version;
    }

    /**
     *  Returns Target URL
     *
     *  @return	  string Target URL
     */
    public function getCybermutUrl()
    {
        if ($this->getConfigData('test_mode')) {
            return "https://p.monetico-services.com/test/paiement.cgi";
        } else {
            return "https://p.monetico-services.com/paiement.cgi";
        }
    }

    /**
     *  Return back URL
     *
     *  @return	  string URL
     */
    protected function getReturnURL()
    {
        return $this->getErrorURL();
    }

    /**
     *  Return URL for Cybermut success response
     *
     *  @return	  string URL
     */
    abstract protected function getSuccessURL();

    /**
     *  Return URL for Cybermut failure response
     *
     *  @return	  string URL
     */
    abstract protected function getErrorURL();

    /**
     *  Return URL for Cybermut notify response
     *
     *  @return	  string URL
     */
    abstract protected function getNotifyURL();

    /**
     * Get quote model
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        if (!$this->_quote) {
            $quoteId = Mage::getSingleton('checkout/session')->getLastQuoteId();
            $this->_quote = Mage::getModel('sales/quote')->load($quoteId);
        }
        return $this->_quote;
    }

    /**
     * Get real order ids
     *
     * @return string
     */
    public function getOrderList()
    {
        if ($this->getQuote()->getIsMultiShipping()) {
            return Mage::getSingleton('checkout/session')->getRealOrderIds();
        } else {
            return $this->getOrder()->getRealOrderId();
        }
    }

    /**
     * Capture payment
     *
     * @param   Varien_Object $orderPayment
     * @return  Mage_Payment_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        $payment->setStatus(self::STATUS_APPROVED)
                ->setLastTransId($this->getTransactionId());

        return $this;
    }

    /**
     * Validate payment method information object
     *
     * @param   Varien_Object $info
     * @return  Mage_Payment_Model_Abstract
     */
    public function validate()
    {
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $quote->setCustomerNoteNotify(false);
        parent::validate();
    }

    /**
     *  Form block description
     *
     *  @return	 object
     */
    public function createFormBlock($name)
    {
        $block = $this->getLayout()->createBlock('cybermut/form_payment', $name);
        $block->setMethod($this->_code);
        $block->setPayment($this->getPayment());

        return $block;
    }

    /**
     *  Return Order Place Redirect URL
     *
     *  @return	  string Order Redirect URL
     */
    abstract public function getOrderPlaceRedirectUrl();

    public function getAmount()
    {
        if ($this->getQuote()->getIsMultiShipping()) {
            $amount = $this->getQuote()->getBaseGrandTotal();
        } else {
            $amount = $this->getOrder()->getBaseGrandTotal();
        }

        return sprintf('%.2f', $amount);
    }

    /**
     *  Return Form Fields for request to Cybermut
     *
     *  @return	  array Array of hidden form fields
     */
    public function getFormFields()
    {
        $session = Mage::getSingleton('checkout/session');

        $order = $this->getOrder();
        if (!($order instanceof Mage_Sales_Model_Order)) {
            Mage::throwException($this->_getHelper()->__('Cannot retrieve order object'));
        }

        $description = $this->getConfigData('description') ? $this->getConfigData('description') : Mage::helper('cybermut')->__('Order %s', $this->getOrderList());

        $fields = array(
            'TPE' => $this->getConfigData('tpe_no'),
            'contexte_commande' => base64_encode(utf8_encode(json_encode($this->getContext($order)))),
            'date' => date('d/m/Y:H:i:s'),
            'lgue' => $this->_getLanguageCode(),
            'mail'	=> $order->getCustomerEmail(),
            'montant' => (string) $this->getAmount() . $order->getBaseCurrencyCode(),
            'reference' => $this->getOrderList(),
            'societe' => $this->getConfigData('site_code'),
            'texte-libre' => $description,
            'url_retour_err' => $this->getErrorURL(),
            'url_retour_ok' => $this->getSuccessURL(),
            'version' => $this->getVersion(),
        );

        $hashable = $this->getHashable($fields);
        $mac = $this->computeHmac($hashable);
        $fields['MAC'] = $mac;

        return $fields;
    }

    /**
     * Get the hashable string from the array
     *
     * @param $fields
     * @return string
     */
    protected function getHashable($fields)
    {
        // Formats the values in the following way : Nom_champ=Valeur_champ
        array_walk($fields, function (&$value, $key) {$value = "$key=$value";});

        // Make it as a single string with * as separation character
        $hashable = join("*", $fields);

        return $hashable;
    }

    /**
     * Generate the context (only the required fields)
     *
     * @param Mage_Sales_Model_Order $order
     * @return array
     */
    protected function getContext($order)
    {
        $data = array();
        $data['billing'] = array();
        // Get the billing address
        $billingAddress = $order->getBillingAddress();
        $data['billing']['name'] = substr($billingAddress->getFirstname() . " " . $billingAddress->getLastname(), 0, 45);
        $data['billing']['firstName'] = substr($billingAddress->getFirstname(), 0, 45);
        $data['billing']['lastName'] = substr($billingAddress->getLastname(), 0, 45);
        if (is_array($billingAddress->getStreet())) {
            $streetFull = implode(" ", $billingAddress->getStreet());
            $data['billing']['address'] = substr($streetFull, 0, 255);
        } else {
            $data['billing']['address'] = substr($billingAddress->getStreet(), 0, 255);
        }

        $data['billing']['addressLine1'] = substr($billingAddress->getStreet1(), 0, 50);
        if ($billingAddress->getStreet2()) {
            $data['billing']['addressLine2'] = substr($billingAddress->getStreet2(), 0, 50);
        }

        if ($billingAddress->getStreet3()) {
            $data['billing']['addressLine3'] = substr($billingAddress->getStreet3(), 0, 50);
        }

        $data['billing']['city'] = substr($billingAddress->getCity(), 0, 50);
        $data['billing']['postalCode'] = $billingAddress->getPostcode();
        if ($regionId = $billingAddress->getRegionId()) {
            $region = Mage::getModel('directory/region')->load($billingAddress->getRegionId());
            $countryId = $region->getCountryId();
            $code = $region->getCode();
            if ($countryId
                && $region) {
                $data['billing']['stateOrProvince'] = $countryId . "_" . $code;
            }
        }

        $data['billing']['country'] = Mage::getModel('directory/country')->load($billingAddress->getCountryId())->getIso2Code();
        if ($billingAddress->getPhone()) {
            $data['billing']['phone'] = (substr($billingAddress->getPhone(),0,1) == "+")
                ? substr($billingAddress->getPhone(), 0, 18)
                : "";
        }

        $data['shipping'] = array();
        // Get the shipping address
        if ($order->getShippingAddress()) {
            $shippingAddress = $order->getShippingAddress();
            $data['shipping']['name'] = substr($shippingAddress->getFirstname() . " " . $shippingAddress->getLastname(), 0, 45);
            $data['shipping']['firstName'] = substr($shippingAddress->getFirstname(), 0, 45);
            $data['shipping']['lastName'] = substr($shippingAddress->getLastname(), 0, 45);
            if (is_array($shippingAddress->getStreet())) {
                $streetFull = implode(" ", $shippingAddress->getStreet());
                $data['shipping']['address'] = substr($streetFull, 0, 255);
            } else {
                $data['shipping']['address'] = substr($shippingAddress->getStreet(), 0, 255);
            }

            $data['shipping']['addressLine1'] = substr($shippingAddress->getStreet1(), 0, 50);
            if ($shippingAddress->getStreet2()) {
                $data['shipping']['addressLine2'] = substr($shippingAddress->getStreet2(), 0, 50);
            }

            if ($shippingAddress->getStreet3()) {
                $data['shipping']['addressLine3'] = substr($shippingAddress->getStreet3(), 0, 50);
            }

            $data['shipping']['city'] = substr($shippingAddress->getCity(), 0, 50);
            $data['shipping']['postalCode'] = $shippingAddress->getPostcode();
            if ($regionId = $shippingAddress->getRegionId()) {
                $region = Mage::getModel('directory/region')->load($shippingAddress->getRegionId());
                $countryId = $region->getCountryId();
                $code = $region->getCode();
                if ($countryId
                    && $region) {
                    $data['shipping']['stateOrProvince'] = $countryId . "_" . $code;
                }
            }

            $data['shipping']['country'] = Mage::getModel('directory/country')->load($shippingAddress->getCountryId())->getIso2Code();
            if ($shippingAddress->getPhone()) {
                $data['shipping']['phone'] = (substr($shippingAddress->getPhone(),0,1) == "+")
                    ? substr($shippingAddress->getPhone(), 0, 18)
                    : "";
            }
        } else {
            $data['shipping'] = $data['billing'];
        }

        return $data;
    }

    /**
     *  Return vars to pur in the URL
     *  Fabrication des données GET au cas où la transmission Cybermut -> serveur Magento n'a pas fonctionnée
     *
     *  @deprecated
     *  @param	  array
     *  @return	  String
     */
    protected function _getUrlVars($code_retour)
    {
        $order = $this->getOrder();
        $session = Mage::getSingleton('checkout/session');

        $description = $this->getConfigData('description') ? $this->getConfigData('description') : Mage::helper('cybermut')->__('Order %s', $order->getRealOrderId());

        $get['retourPLUS'] = "--no-option";
        $get['TPE'] = $this->getConfigData('tpe_no');
        $get['date'] = date('d/m/Y:H:i:s');
        $get['montant'] = sprintf('%.2f', $order->getBaseGrandTotal()) . $order->getBaseCurrencyCode();
        $get['reference'] = $order->getRealOrderId();
        $get['texte-libre'] = $description;
        $get['code-retour'] = $code_retour;

        return "?MAC=" . $this->getResponseMACV2($get)
                . "&TPE=" . $this->getConfigData('tpe_no')
                . "&date=" . date('d/m/Y:H:i:s')
                . "&montant=" . sprintf('%.2f', $order->getBaseGrandTotal()) . $order->getBaseCurrencyCode()
                . "&reference=" . $order->getRealOrderId()
                . "&texte-libre=" . $description
                . "&code-retour=" . $code_retour
                . "&retourPLUS=--no-option";
    }

    /**
     *  Prepare string for MAC generation
     *
     *  @deprecated
     *  @param    none
     *  @return	  string MAC string
     */
    protected function _getMAC($data)
    {
        if (((int)$this->getVersion()) >= 3) {
            $string = sprintf('%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*', $data['TPE'], $data['date'], $data['montant'], $data['reference'], $data['texte-libre'], $data['version'], $data['lgue'], $data['societe'], $data['mail'], "", "", "", "", "", "", "", "", "");
        } else {
            $string = sprintf('%s*%s*%s*%s*%s*%s*%s*%s*', $data['TPE'], $data['date'], $data['montant'], $data['reference'], $data['texte-libre'], $data['version'], $data['lgue'], $data['societe']);
        }

        return $this->_CMCIC_hmac($string);
    }

    /**
     * Compute the MAC
     *
     * @param $sData
     * @return string
     */
    public function computeHmac($sData)
    {
        return strtolower(hash_hmac("sha1", $sData, $this->_getKeyEncrypted()));
    }

    /**
     * Return MAC string on basis of Cybermut response data
     *
     * @param $data
     * @return mixed
     */
    public function getResponseMACV2($data)
    {
        if (array_key_exists('MAC', $data)) {
            unset($data['MAC']);
        }

        $hashable = $this->getHashable($data);
        return $this->computeHmac($data);
    }

    /**
     *  Return MAC string on basis of Cybermut response data
     *
     *  @deprecated
     *  @param    none
     *  @return	  string MAC
     */
    public function getResponseMAC($data)
    {
        if (((int)$this->getVersion()) >= 3) {
            if (!array_key_exists('numauto', $data)) {
                $data['numauto'] = "";
            }
            if (!array_key_exists('motifrefus', $data)) {
                $data['motifrefus'] = "";
            }
            if (!array_key_exists('originecb', $data)) {
                $data['originecb'] = "";
            }
            if (!array_key_exists('bincb', $data)) {
                $data['bincb'] = "";
            }
            if (!array_key_exists('hpancb', $data)) {
                $data['hpancb'] = "";
            }
            if (!array_key_exists('ipclient', $data)) {
                $data['ipclient'] = "";
            }
            if (!array_key_exists('originetr', $data)) {
                $data['originetr'] = "";
            }
            if (!array_key_exists('veres', $data)) {
                $data['veres'] = "";
            }
            if (!array_key_exists('pares', $data)) {
                $data['pares'] = "";
            }

            $string = sprintf('%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*', $data['TPE'], $data['date'], $data['montant'], $data['reference'], $data['texte-libre'], '3.0', $data['code-retour'], $data['cvx'], $data['vld'], $data['brand'], $data['status3ds'], $data['numauto'], $data['motifrefus'], $data['originecb'], $data['bincb'], $data['hpancb'], $data['ipclient'], $data['originetr'], $data['veres'], $data['pares']);
        } else {
            $string = sprintf('%s%s+%s+%s+%s+%s+%s+%s+', $data['retourPLUS'], $data['TPE'], $data['date'], $data['montant'], $data['reference'], $data['texte-libre'], $this->getVersion(), $data['code-retour']);
        }

        return strtoupper($this->_CMCIC_hmac($string));
    }

    /**
     *  Return SHA key
     *
     *  @param    none
     *  @return	  string SHA key
     */
    protected function _getSHAKey()
    {
        return $this->getConfigData('sha_key');
    }

    /**
     *  Return merchant key
     *
     *  @param    none
     *  @return	  string Merchant key
     */
    protected function _getKey()
    {
        return $this->getConfigData('key');
    }

    /**
     *  Return encrypted key
     *
     *  @param    none
     *  @return	  string encrypted key
     */
    /* protected function _getKeyEncrypted()
      {
      $key = $this->getConfigData('key_encrypted');
      $key = Mage::helper('core')->decrypt($key);

      $avant_dernier_tranforme = (ord(substr($key, strlen($key) - 2, 1)) - 23);
      $key = substr($key, 0, strlen($key) - 2) . chr($avant_dernier_tranforme) . substr($key, strlen($key) - 1, 1);

      return pack("H*", $key);
      } */

    protected function _getKeyEncrypted()
    {
        $key = $this->getConfigData('key_encrypted');
        $key = Mage::helper('core')->decrypt($key);

        $hexStrKey = substr($key, 0, 38);
        $hexFinal = "" . substr($key, 38, 2) . "00";

        $cca0 = ord($hexFinal);

        if ($cca0 > 70 && $cca0 < 97) {
            $hexStrKey .= chr($cca0 - 23) . substr($hexFinal, 1, 1);
        } else {
            if (substr($hexFinal, 1, 1) == "M") {
                $hexStrKey .= substr($hexFinal, 0, 1) . "0";
            } else {
                $hexStrKey .= substr($hexFinal, 0, 2);
            }
        }

        return pack("H*", $hexStrKey);
    }

    /**
     *  Select old and new system
     *
     *  @deprecated
     *  @param    string
     *  @return	  string encrypted key
     */
    protected function _CMCIC_hmac($string)
    {
        if ($this->getConfigData('key_encrypted')) {
            return $this->_CMCIC_hmac_KeyEncrypted($string);
        } else {
            return $this->_CMCIC_hmac_KeyPassphrase($string);
        }
    }

    /**
     * Return MAC string for payment authentification
     * new system
     *
     *  @deprecated
     *  @param    string
     *  @return	  string encrypted key
     */
    protected function _CMCIC_hmac_KeyEncrypted($string)
    {
        $key = $this->_getKeyEncrypted();

        $length = 64; // block length for SHA1
        if (strlen($key) > $length) {
            $key = pack("H*", sha1($key));
        }

        $key = str_pad($key, $length, chr(0x00));
        $ipad = str_pad('', $length, chr(0x36));
        $opad = str_pad('', $length, chr(0x5c));
        $k_ipad = $key ^ $ipad;
        $k_opad = $key ^ $opad;

        return sha1($k_opad . pack("H*", sha1($k_ipad . $string)));
    }

    /**
     * Return MAC string for payment authentification
     * old HMAC system
     *  @deprecated
     *  @param    string
     *  @return	  string encrypted key
     */
    protected function _CMCIC_hmac_KeyPassphrase($string)
    {
        $pass = $this->_getSHAKey();
        $k1 = pack("H*", sha1($this->_getSHAKey()));
        $l1 = strlen($k1);
        $k2 = pack("H*", $this->_getKey());
        $l2 = strlen($k2);
        if ($l1 > $l2) {
            $k2 = str_pad($k2, $l1, chr(0x00));
        } elseif ($l2 > $l1) {
            $k1 = str_pad($k1, $l2, chr(0x00));
        }

        return strtolower($this->_hmacSHA1($k1 ^ $k2, $string));
    }

    /**
     *  Old HMAC SHA system
     *
     *  @param    string
     *  @return	  string encrypted key
     */
    protected function _hmacSHA1($key, $string)
    {
        $length = 64; // block length for SHA1
        if (strlen($key) > $length) {
            $key = pack("H*", sha1($key));
        }

        $key = str_pad($key, $length, chr(0x00));
        $ipad = str_pad('', $length, chr(0x36));
        $opad = str_pad('', $length, chr(0x5c));
        $k_ipad = $key ^ $ipad;
        $k_opad = $key ^ $opad;

        return sha1($k_opad . pack("H*", sha1($k_ipad . $string)));
    }

    /**
     *  Return MAC string for payment authentification
     *
     *  @param    none
     *  @return	  string MAC
     */
    /* <FONCTION OBSOLETE>
      protected function _CMCIC_hmac($string)
      {
      $pass = $this->_getSHAKey();
      $k1 = pack("H*", sha1($this->_getSHAKey()));
      $l1 = strlen($k1);
      $k2 = pack("H*", $this->_getKey());
      $l2 = strlen($k2);
      if ($l1 > $l2) {
      $k2 = str_pad($k2, $l1, chr(0x00));
      } elseif ($l2 > $l1) {
      $k1 = str_pad($k1, $l2, chr(0x00));
      }

      return strtolower($this->_hmacSHA1($k1 ^ $k2, $string));
      }

      <FONCTION OBSOLETE> */

    /**
     *  MAC generation algorithm
     *
     *  @param    none
     *  @return	  string MAC
     */
    /* <FONCTION OBSOLETE>
      protected function _hmacSHA1($key, $string)
      {
      $length = 64; // block length for SHA1
      if (strlen($key) > $length) {
      $key = pack("H*", sha1($key));
      }

      $key = str_pad($key, $length, chr(0x00));
      $ipad = str_pad('', $length, chr(0x36));
      $opad = str_pad('', $length, chr(0x5c));
      $k_ipad = $key ^ $ipad;
      $k_opad = $key ^ $opad;

      return sha1($k_opad  . pack("H*",sha1($k_ipad . $string)));
      }

      </FONCTIONS OBSOLETES> */

    /**
     * Return authorized languages by CyberMUT
     *
     * @param	none
     * @return	array
     */
    protected function _getAuthorizedLanguages()
    {
        $languages = array();

        foreach (Mage::getConfig()->getNode('global/payment/cybermut_payment/languages')->asArray() as $data) {
            $languages[$data['code']] = $data['name'];
        }

        return $languages;
    }

    /**
     * Return language code to send to CyberMUT
     *
     * @param	none
     * @return	String
     */
    protected function _getLanguageCode()
    {
        // Store language
        $language = strtoupper(substr(Mage::getStoreConfig('general/locale/code'), 0, 2));

        // Authorized Languages
        $authorized_languages = $this->_getAuthorizedLanguages();

        if (count($authorized_languages) === 1) {
            $codes = array_keys($authorized_languages);
            return $codes[0];
        }

        if (array_key_exists($language, $authorized_languages)) {
            return $language;
        }

        // By default we use language selected in store admin
        return $this->getConfigData('language');
    }

    /**
     *  Transaction successful or not
     *
     *  @param    none
     *  @return	  boolean
     */
    public function isSuccessfulPayment($returnCode)
    {
        return in_array($returnCode, array(self::RETURN_CODE_ACCEPTED, self::RETURN_CODE_TEST_ACCEPTED));
    }

    /**
     *  Output success response and stop the script
     *
     *  @param    none
     *  @return	  void
     */
    public function generateSuccessResponse()
    {
        die($this->getSuccessResponse());
    }

    /**
     *  Output failure response and stop the script
     *
     *  @param    none
     *  @return	  void
     */
    public function generateErrorResponse()
    {
        die($this->getErrorResponse());
    }

    /**
     *  Return response for Cybermut success payment
     *
     *  @param    none
     *  @return	  string Success response string
     */
    public function getSuccessResponse()
    {
        if (((int)$this->getVersion()) >= 3) {
            $response = array(
                'version=2',
                'cdr=0'
            );
        } else {
            $response = array(
                'Pragma: no-cache',
                'Content-type : text/plain',
                'Version: 1',
                'OK'
            );
        }

        return implode("\n", $response) . "\n";
    }

    /**
     *  Return response for Cybermut failure payment
     *
     *  @param    none
     *  @return	  string Failure response string
     */
    public function getErrorResponse()
    {
        if (((int)$this->getVersion()) >= 3) {
            $response = array(
                'version=2',
                'cdr=1'
            );
        } else {
            $response = array(
                'Pragma: no-cache',
                'Content-type : text/plain',
                'Version: 1',
                'Document falsifie'
            );
        }

        return implode("\n", $response) . "\n";
    }

    public function getSuccessfulPaymentMessage($postData)
    {
        $msg = Mage::helper('cybermut')->__('Payment accepted by Cybermut');

        if (((int)$this->getVersion()) >= 3 && array_key_exists('numauto', $postData)) {
            $msg .= "<br />" . Mage::helper('cybermut')->__('Number of authorization: %s', $postData['numauto']);
            $msg .= "<br />" . Mage::helper('cybermut')->__('Was the visual cryptogram seized: %s', $postData['cvx']);
            $msg .= "<br />" . Mage::helper('cybermut')->__('Validity of the card: %s', $postData['vld']);
            $msg .= "<br />" . Mage::helper('cybermut')->__('Type of the card: %s', $postData['brand']);
        }

        return $msg;
    }

    public function getRefusedPaymentMessage($postData)
    {
        $msg = Mage::helper('cybermut')->__('Payment refused by Cybermut');

        if (((int)$this->getVersion()) >= 3 && array_key_exists('motifrefus', $postData)) {
            $msg .= "<br />" . Mage::helper('cybermut')->__('Motive for refusal: %s', $postData['motifrefus']);
            $msg .= "<br />" . Mage::helper('cybermut')->__('Was the visual cryptogram seized: %s', $postData['cvx']);
            $msg .= "<br />" . Mage::helper('cybermut')->__('Validity of the card: %s', $postData['vld']);
            $msg .= "<br />" . Mage::helper('cybermut')->__('Type of the card: %s', $postData['brand']);
        }

        return $msg;
    }

}
