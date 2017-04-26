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
abstract class Quadra_Cybermut_Controller_Action extends Mage_Core_Controller_Front_Action
{

    protected $_cybermutResponse = null;
    protected $_realOrderIds;
    protected $_quote;

    /**
     * Get current Cybermut Method Instance
     *
     * @return Quadra_Cybermut_Model_Payment|Quadra_Cybermut_Model_Several
     */
    abstract public function getMethodInstance();

    /**
     * Get quote model
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        if (!$this->_quote) {
            $session = Mage::getSingleton('checkout/session');
            $this->_quote = Mage::getModel('sales/quote')->load($session->getCybermutPaymentQuoteId());

            if (!$this->_quote->getId()) {
                $realOrderIds = $this->getRealOrderIds();
                if (count($realOrderIds)) {
                    $order = Mage::getModel('sales/order')->loadByIncrementId($realOrderIds[0]);
                    $this->_quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
                }
            }
        }
        return $this->_quote;
    }

    /**
     * Get real order ids
     *
     * @return array
     */
    public function getRealOrderIds()
    {
        if (!$this->_realOrderIds) {
            if ($this->_cybermutResponse) {
                $this->_realOrderIds = explode(',', $this->_cybermutResponse['reference']);
            } elseif ($realOrderIds = Mage::getSingleton('checkout/session')->getCybermutRealOrderIds()) {
                $this->_realOrderIds = explode(',', $realOrderIds);
            } else {
                return array();
            }
        }
        return $this->_realOrderIds;
    }

    /**
     * seting response after returning from cybermut
     *
     * @param array $response
     * @return object $this
     */
    protected function setCybermutResponse($response)
    {
        if (count($response)) {
            $this->_cybermutResponse = $response;
        }
        return $this;
    }

    /**
     * When a customer chooses Cybermut on Checkout/Payment page
     *
     */
    public function redirectAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setCybermutPaymentQuoteId($session->getLastQuoteId());

        if ($this->getQuote()->getIsMultiShipping()) {
            $realOrderIds = explode(',', $session->getRealOrderIds());
            $session->setCybermutRealOrderIds($session->getRealOrderIds());
        } else {
            $realOrderIds = array($session->getLastRealOrderId());
            $session->setCybermutRealOrderIds($session->getLastRealOrderId());
        }

        foreach ($realOrderIds as $realOrderId) {
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($realOrderId);

            if (!$order->getId()) {
                // Debug data when order can not be loaded
                $this->getMethodInstance()->debugData($realOrderIds);
                $this->getMethodInstance()->debugData($session->getData());

                $this->norouteAction();
                return;
            }

            $order->addStatusHistoryComment(Mage::helper('cybermut')->__('Customer was redirected to Cybermut'));
            $order->save();
        }

        $this->getResponse()
                ->setBody($this->getLayout()
                        ->createBlock('cybermut/redirect')
                        ->setOrder($order)
                        ->setMethodInstance($this->getMethodInstance())
                        ->toHtml());

        $session->unsQuoteId();
    }

    /**
     *  Cybermut response router
     *
     *  @param    none
     *  @return	  void
     */
    public function notifyAction()
    {
        $model = $this->getMethodInstance();

        if ($this->getRequest()->isPost()) {
            $postData = $this->getRequest()->getPost();
            $method = 'post';
        } else if ($this->getRequest()->isGet()) {
            $postData = $this->getRequest()->getQuery();
            $method = 'get';
        } else {
            $model->generateErrorResponse();
        }

        $this->setCybermutResponse($postData);

        if ($model->getDebugFlag()) {
            Mage::getModel('cybermut/api_debug')
                    ->setResponseBody(print_r($postData, 1))
                    ->save();
        }

        $returnedMAC = $postData['MAC'];
        $correctMAC = $model->getResponseMAC($postData);

        $returned3DSStatus = (int) $postData['status3ds'];
        switch ($returned3DSStatus) {
            // la transaction ne s'est pas faite selon le protocole 3DSecure
            case -1:
            default:
                $orderStatusPaymentAccepted = 'order_status_payment_accepted_no_3ds';
                break;
            // la transaction s'est faite selon le protocole 3DS et le niveau de risque est faible
            case 1:
                $orderStatusPaymentAccepted = 'order_status_payment_accepted_3ds_low_risk';
                break;
            // la transaction ne peut se faire selon le protocole 3DSecure, le porteur a cependant été authentifié par le biais de 3DSecure
            case 2:
                $orderStatusPaymentAccepted = 'order_status_payment_accepted_no_3ds_but_user_authentified';
                break;
            // la transaction s'est faite selon le protocole 3DS et le niveau de risque est élevé
            case 3:
                $orderStatusPaymentAccepted = 'order_status_payment_accepted_3ds_high_risk';
                break;
            // la transaction s'est faite selon le protocole 3DS et le niveau de risque est très élevé
            case 4:
                $orderStatusPaymentAccepted = 'order_status_payment_accepted_3ds_very_high_risk';
                break;
        }

        foreach ($this->getRealOrderIds() as $realOrderId) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($realOrderId);

            if (!$order->getId()) {
                $model->generateErrorResponse();
            }
        }

        if ($returnedMAC == $correctMAC) {
            if ($model->isSuccessfulPayment($postData['code-retour'])) {
                foreach ($this->getRealOrderIds() as $realOrderId) {
                    $order = Mage::getModel('sales/order')->loadByIncrementId($realOrderId);

                    // Déblocage de la commande si nécessaire
                    if ($order->getState() == Mage_Sales_Model_Order::STATE_HOLDED) {
                        $order->unhold();
                    }

                    if (!$status = $model->getConfigData($orderStatusPaymentAccepted)) {
                        $status = $model->getConfigData('order_status_payment_accepted');
                    }
                    if (!$status) {
                        $status = $order->getStatus();
                    }

                    $message = $model->getSuccessfulPaymentMessage($postData);

                    if ($status == Mage_Sales_Model_Order::STATE_PROCESSING) {
                        if ($model->getConfigData('invoice_create')) {
                            $this->saveInvoice($order);
                        }
                        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, $status, $message);
                    } else if ($status == Mage_Sales_Model_Order::STATE_COMPLETE) {
                        $this->saveInvoice($order);
                        if ($order->canShip()) {
                            $itemQty = $order->getItemsCollection()->count();
                            $shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($itemQty);
                            $shipment = new Mage_Sales_Model_Order_Shipment_Api();
                            $shipment->create($order->getIncrementId());
                        }
                    } else {
                        if ($model->getConfigData('invoice_create')) {
                            $this->saveInvoice($order);
                        }
                        $order->addStatusToHistory($status, $message, true);
                    }
                    $order->save();

                    if (!$order->getEmailSent()) {
                        $order->sendNewOrderEmail();
                    }
                }
            } else {
                foreach ($this->getRealOrderIds() as $realOrderId) {
                    $order = Mage::getModel('sales/order')->loadByIncrementId($realOrderId);

                    $messageError = $model->getRefusedPaymentMessage($postData);

                    if ($order->getState() == Mage_Sales_Model_Order::STATE_HOLDED) {
                        $order->unhold();
                    }

                    if (!$status = $model->getConfigData('order_status_payment_refused')) {
                        $status = $order->getStatus();
                    }

                    if ($status == Mage_Sales_Model_Order::STATE_HOLDED && $order->canHold()) {
                        $order->hold();
                    } elseif ($status == Mage_Sales_Model_Order::STATE_CANCELED && $order->canCancel()) {
                        $order->cancel();
                    }
                    $order->addStatusHistoryComment($messageError);
                    $order->save();
                }
            }

            if ($method == 'post') {
                $model->generateSuccessResponse();
            } else if ($method == 'get') {
                return;
            }
        } else {
            foreach ($this->getRealOrderIds() as $realOrderId) {
                $order = Mage::getModel('sales/order')->loadByIncrementId($realOrderId);
                if ($order->canCancel())
                    $order->cancel();
                $order->addStatusHistoryComment(Mage::helper('cybermut')->__('Returned MAC is invalid. Order cancelled.'));
                $order->save();
            }
            $model->generateErrorResponse();
        }
    }

    /**
     *  Save invoice for order
     *
     *  @param    Mage_Sales_Model_Order $order
     *  @return	  boolean Can save invoice or not
     */
    protected function saveInvoice(Mage_Sales_Model_Order $order, $ship = false)
    {
        if ($order->canInvoice()) {
            $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
            $invoice->register()->capture();

            $transactionSave = Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());

            if ($ship && $order->canShip()) {
                $shipment = Mage::getModel('sales/service_order', $order)->prepareShipment();
                $shipment->register();
                $transactionSave->addObject($shipment);
            }

            $transactionSave->save();
            return true;
        }

        return false;
    }

    /**
     *  Success payment page
     *
     *  @param    none
     *  @return	  void
     */
    public function successAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getCybermutPaymentQuoteId());
        $session->unsCybermutPaymentQuoteId();
        $session->setCanRedirect(false);
        $session->setIsMultishipping(false);

        if ($this->getQuote()->getIsMultiShipping())
            $orderIds = array();

        foreach ($this->getRealOrderIds() as $realOrderId) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($realOrderId);

            if (!$order->getId()) {
                $this->norouteAction();
                return;
            }

            $order->addStatusHistoryComment(Mage::helper('cybermut')->__('Customer successfully returned from Cybermut'));
            $order->save();

            if ($this->getQuote()->getIsMultiShipping())
                $orderIds[$order->getId()] = $realOrderId;
        }

        if ($this->getQuote()->getIsMultiShipping()) {
            Mage::getSingleton('checkout/type_multishipping')
                    ->getCheckoutSession()
                    ->setDisplaySuccess(true)
                    ->setPayboxResponseCode('success');

            Mage::getSingleton('core/session')->setOrderIds($orderIds);
            Mage::getSingleton('checkout/session')->setIsMultishipping(true);
        }

        $this->_redirect($this->_getSuccessRedirect());
    }

    /**
     *  Failure payment page
     *
     *  @param    none
     *  @return	  void
     */
    public function errorAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $model = $this->getMethodInstance();

        $session->setIsMultishipping(false);

        foreach ($this->getRealOrderIds() as $realOrderId) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($realOrderId);

            if (!$order->getId()) {
                continue;
            } else if ($order instanceof Mage_Sales_Model_Order && $order->getId()) {
                if (!$status = $model->getConfigData('order_status_payment_canceled')) {
                    $status = $order->getStatus();
                }

                if ($status == Mage_Sales_Model_Order::STATE_HOLDED && $order->canHold()) {
                    $order->hold();
                } elseif ($status == Mage_Sales_Model_Order::STATE_CANCELED && $order->canCancel()) {
                    $order->cancel();
                }
                $order->addStatusHistoryComment($this->__('Order was canceled by customer'));
                $order->save();
            }
        }

        if (!$model->getConfigData('empty_cart')) {
            $this->_reorder();
        }

        $this->_redirect($this->_getErrorRedirect());
    }

    protected function _reorder()
    {
        $cart = Mage::getSingleton('checkout/cart');
        $cartTruncated = false;
        /* @var $cart Mage_Checkout_Model_Cart */

        foreach ($this->getRealOrderIds() as $realOrderId) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($realOrderId);

            if ($order->getId()) {
                $items = $order->getItemsCollection();
                foreach ($items as $item) {
                    try {
                        $cart->addOrderItem($item);
                    } catch (Mage_Core_Exception $e) {
                        if (Mage::getSingleton('checkout/session')->getUseNotice(true)) {
                            Mage::getSingleton('checkout/session')->addNotice($e->getMessage());
                        } else {
                            Mage::getSingleton('checkout/session')->addError($e->getMessage());
                        }
                    } catch (Exception $e) {
                        Mage::getSingleton('checkout/session')->addException($e, Mage::helper('checkout')->__('Cannot add the item to shopping cart.')
                        );
                    }
                }
            }
        }

        $cart->save();
    }

    protected function _getSuccessRedirect()
    {
        if ($this->getQuote()->getIsMultiShipping())
            return 'checkout/multishipping/success';
        else
            return 'checkout/onepage/success';
    }

    protected function _getErrorRedirect()
    {
        if ($this->getQuote()->getIsMultiShipping()) {
            return 'checkout/cart';
        } else {
            return 'checkout/onepage/failure';
        }
    }

}
