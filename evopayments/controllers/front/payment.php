<?php

/**
 * EVOPayments
 *
 * @author    EVOPayments
 * @copyright Copyright (c) 2018 EVOPayments
 * @license   http://opensource.org/licenses/LGPL-3.0  Open Software License (LGPL 3.0)
 *
 */
include_once _PS_MODULE_DIR_ . '/evopayments/tools/sdk/payments.php';

class EVOPaymentsPaymentModuleFrontController extends ModuleFrontController
{
    /** @var EVOPayments */
    private $evopayments;

    public $display_column_left = false;

    private $order = null;

    public $ssl = true;

    public function postProcess()
    {
        $this->ssl = true;
        $this->evopayments = new EVOPayments();
        $this->evopayments->initEVOConfig();
		//it's safe to use MD5 here
        $this->merchantCode = substr(md5(uniqid(mt_rand(), true)), 0, 20);
        $this->url = Payments\Config::$BaseUrl;
        $this->mapStatuses = EVOPayments::MAP_STATUSES;
        $this->merchantId = Payments\Config::$MerchantId;
        $this->jsUrl = Payments\Config::$JavaScriptUrl;        
    }

    public function initContent()
    {
        parent::initContent();
        $retry =  Tools::getValue('retry');
        $retryToken =  Tools::getValue('retryToken');
        $evopaymentsPay = Tools::getValue('evopaymentsPay');
        $id_evo = Tools::getValue('evopayment');
        $statusPost = Tools::getValue('status');
        $redirectToEvo = Tools::getValue('redirectToEvo');
        $amount='';

        if ($retry) {
            $this->evopayments->checkAccess($retry, $retryToken);
            $order = new Order($retry);
            $amount = sprintf('%0.2f', $order->total_paid);
        }

        $redirect = Configuration::get('EVO_PAYMENT_TYPE');
        if (!$evopaymentsPay) {
            $tokenEvo = $this->merchantCode;
        } else {
            $tokenEvo = Tools::getValue('token');
        }

        $token = $this->evopayments->getPaymentToken($tokenEvo, $retry, $redirect, $amount);

        if (!$evopaymentsPay) {
            $this->showPayMethod($token, $retry, $tokenEvo);
            return;
        }
        $status = str_replace('"', '', $statusPost);
        $errors = [];

        if (is_array($token)) {
            $errors[] = $this->module->l('Error:', 'payment') . $token['error'] . $this->module->l('Error code: ', 'payment') . $token['errorcode'];
        }

        if($id_evo=='' && !$redirectToEvo){
            $errors[] = $this->module->l('Something went wrong. No answer from the payment gateway. Try again.', 'payment');
        }

        if(count($errors) > 0 ) {
            $this->showPayMethod($token, $retry, $tokenEvo, $errors);
            return;
        }

        //iframe
       $this->payByEvo((int)$id_evo, $status, $retry, $tokenEvo, $retryToken);

    }

    private function payByEvo($id_evo_payment, $status, $retry=0, $tokenEvo, $retryToken){
        if($status==='cancel') {
            if ((int)$this->context->cart->id) {
                Tools::redirect(Tools::getHttpHost(true).__PS_BASE_URI__ . 'index.php?controller=' . (Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc' : 'order'));
            } else {
                Tools::redirect($this->context->link->getPageLink('history', true));
            }
        }

        if($this->evopayments->getPaymentStatus($tokenEvo)->result!==$status){
            $status='failure';
        }

        $id_order_state = (int)Configuration::get($this->mapStatuses[$status]);
        if ((int)$retry !== 0) {
            $order = new Order((int)$retry);
        } else {
            $order = $this->evopayments->createOrder($status);
        }

        if ($order->current_state != $id_order_state) {
            $order->setCurrentState($id_order_state);
        }
        $this->evopayments->updateIdOrderInEvoPayment($id_evo_payment, $order->id);
        $this->updateOrderPayment($order, $status, $id_evo_payment);
        Tools::redirect(Tools::getHttpHost(true) . __PS_BASE_URI__ . 'index.php?fc=module&module=evopayments&controller=success&order=' . $order->id);
    }

    private function updateOrderPayment($order, $status, $id_evo_payment)
    {
        if ($status === 'success') {
            $currency = new Currency($order->id_currency);
            $order->addOrderPayment($order->total_paid, 'EVOPayments', $id_evo_payment, $currency, date('Y-m-d H:i:s'));
        }

        return true;
    }

    private function showPayMethod($token, $retry ='', $merchantTxId, $errors = [])
    {
        $this->context->smarty->assign([
            'image' => $this->evopayments->getEVOLogo(),
            'evoErrors' => $errors
        ]);

        $this->context->smarty->assign($this->getShowPayMethodsParameters($retry, $token, $merchantTxId));

        $this->setTemplate($this->evopayments->buildTemplatePath('payMethods'));
    }

    private function getShowPayMethodsParameters($retry = '', $token, $merchantTxId)
    {
        //use standalone by default
        $integrationMode = 'standalone';
        //Hosted Payment Page
        if((int)Configuration::get('EVO_PAYMENT_TYPE') === 2 ) {
            $integrationMode = 'hostedPayPage';
        }
		
        if ($retry != '') {
            $evoPayment = $this->evopayments->getOrdersByIdOrder($retry);
            $order = new Order($retry);
            $cart = new Cart($order->id_cart);
            $paid = false;

            if($evoPayment['0']['status']==='success'){
                $paid = true;
                Tools::redirect('index.php?controller=history');
            }

            $this->makeJs($this->url, $merchantTxId, $this->context->cart->id, $retry, $token, $this->merchantId,
                Context::getContext()->shop->getBaseURL(true), Configuration::get('EVO_PAYMENT_TYPE'));

            return [
                'total' => Tools::displayPrice($cart->getOrderTotal(true, Cart::BOTH)),
                'orderCurrency' => (int)$order->id_currency,
                'token' => $token,
                'evotoken' => $merchantTxId,
                'retryToken' => Tools::getValue('retryToken'),
                'jsUrl' => $this->jsUrl,
                'baseUrl' => $this->url,
                'retry' => $retry,
                'ifpaid' => $paid,
                'merchantId' => $this->merchantId,
                'paymentSolution' => trim(Configuration::get('EVO_PAYMENT_SOLUTION')),
                'paymentType' => Configuration::get('EVO_PAYMENT_TYPE'),
                'PayAction' => $this->context->link->getModuleLink('evopayments', 'payment'),
				'integrationMode' => $integrationMode,
                'OrderInfo' => $this->module->l('The total amount of your order is', 'payment')
            ];

        }

        if(!$this->context->cart->id){
            Tools::redirect('index.php?controller=cart');
        }

        $this->makeJs($this->url, $merchantTxId, $this->context->cart->id, '', $token, $this->merchantId, Context::getContext()->shop->getBaseURL(true), Configuration::get('EVO_PAYMENT_TYPE'));

        return [
            'total' => Tools::displayPrice($this->context->cart->getOrderTotal(true, Cart::BOTH)),
            'orderCurrency' => (int)$this->context->cart->id_currency,
            'token' => $token,
            'retryToken' => Tools::getValue('retryToken'),
            'jsUrl' => $this->jsUrl,
            'baseUrl' => $this->url,
            'evotoken' => $merchantTxId,
            'cartId' => $this->context->cart->id,
            'ifpaid' => '',
            'retry' => '',
            'merchantId' => $this->merchantId,
            'paymentSolution' => trim(Configuration::get('EVO_PAYMENT_SOLUTION')),
            'paymentType' => Configuration::get('EVO_PAYMENT_TYPE'),
            'PayAction' => $this->context->link->getModuleLink('evopayments', 'payment'),
			'integrationMode' => $integrationMode,
            'OrderInfo' => $this->module->l('The total amount of your order is', 'payment')
        ];
    }

    private function makeJs($baseUrl, $tokenEvo, $cartId, $retry, $token, $merchantId, $baseUri, $paymentType){
        $this->evopayments->addJsVar(
            [
                'paymentType' => $paymentType
            ]
        );
        if((int)$paymentType===1) {
            $this->evopayments->addJsVar(
                [
                    'baseUrl' => $baseUrl,
                    'evotoken' => $tokenEvo,
                    'cartId' => $cartId,
                    'retry' => $retry,
                    'token' => $token,
                    'merchantId' => $merchantId,
                    'baseUri' => $baseUri,
                    'paymentType' => $paymentType
                ]
            );
        }else{
            $this->evopayments->addJsVar(
                [
                    'baseUrl' => $baseUrl,
                    'evotoken' => $tokenEvo,
                    'cartId' => $cartId
                ]
                );
        }
    }
}
