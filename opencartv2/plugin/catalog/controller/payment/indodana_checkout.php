<?php

require_once DIR_SYSTEM . 'library/indodana/autoload.php';

use IndodanaCommon\IndodanaHelper;
use IndodanaCommon\IndodanaConstant;
use IndodanaCommon\IndodanaInterface;
use IndodanaCommon\IndodanaLogger;
use IndodanaCommon\IndodanaCommon;
use IndodanaCommon\MerchantResponse;

class ControllerPaymentIndodanaCheckout extends Controller implements IndodanaInterface
{
  private $data = [];
  private $indodana_common;
  private $default_currency;

  private static function decode($html_entity) {
    return html_entity_decode($html_entity, ENT_QUOTES, 'UTF-8');
  }

  private function getIndodanaCommon()
  {
    if (!isset($this->indodana_common)) {
      $this->indodana_common = new IndodanaCommon([
        'apiKey'        => $this->config->get('indodana_checkout_api_key'),
        'apiSecret'     => $this->config->get('indodana_checkout_api_secret'),
        'environment'   => $this->config->get('indodana_checkout_environment'),
        'seller'        => $this->getSeller(),
      ]);
    }

    return $this->indodana_common;
  }

  private function getDefaultCurrency()
  {
    if (!isset($this->default_currency)) {
      $this->default_currency = $this->config->get('config_currency');
    }

    return $this->default_currency;
  }

  public function getTotalAmount($order)
  {
    $total_rows = $this->model_payment_indodana_checkout->getTotalRows($order['order_id']);

    return $this->getTotalValueOrderTotalRows($total_rows);
  }

  public function getTotalDiscountAmount($order)
  {
    $discount_rows = $this->model_payment_indodana_checkout->getDiscountRows($order['order_id']);

    return $this->getTotalValueOrderTotalRows($discount_rows);
  }

  public function getTotalShippingAmount($order)
  {
    $shipping_rows = $this->model_payment_indodana_checkout->getShippingRows($order['order_id']);
    
    return $this->getTotalValueOrderTotalRows($shipping_rows);
  }

  public function getTotalTaxAmount($order)
  {
    $tax_rows = $this->model_payment_indodana_checkout->getTaxRows($order['order_id']);

    return $this->getTotalValueOrderTotalRows($tax_rows);
  }

  private function getTotalValueOrderTotalRows($rows)
  {
    return array_reduce(
      $rows,
      function ($carry, $row) {
        // All value will be converted to IDR
        $value = $this->currency->convert((float) $row['value'], $this->getDefaultCurrency(), "IDR");

        $carry += abs($value);

        return $carry;
      },
      0
    );
  }

  public function getProducts($order)
  {
    $products = [];

    $order_id = $order['order_id'];

    $order_products = $this->model_account_order->getOrderProducts($order_id);

    foreach ($order_products as $order_product) {
      $product_id = $order_product['product_id'];
      $product = $this->model_catalog_product->getProduct($product_id);

      // Get URL
      $url = $this->url->link(
        'product/product',
        'product_id=' . $product_id
      );

      // Get Image URL
      $image_url = $this->model_tool_image->resize(
        $product['image'],
        $this->config->get('config_image_popup_width'),
        $this->config->get('config_image_popup_height')
      );

      // Get type
      $type = '';

      $productCategories = $this->model_catalog_product->getCategories($product_id);

      if (!empty($productCategories)) {
        $category_id = $productCategories[0]['category_id'];

        $category = $this->model_catalog_category->getCategory($category_id);

        $type = $category['name'];
      }

      $products[] = [
        'id'        => self::decode($product_id),
        'name'      => self::decode($order_product['name']),
        'price'     => (float) self::decode($order_product['price']),
        'url'       => self::decode($url),
        'imageUrl'  => self::decode($image_url),
        'type'      => self::decode($type),
        // We will use DEFAULT_ITEM_CATEGORY atm until we found a way to get specific plugin category mapping
        'category'  => IndodanaConstant::DEFAULT_ITEM_CATEGORY,
        'quantity'  => (int) self::decode($order_product['quantity']),
      ];
    }

    return $products;
  }

  public function getCustomerDetails($order) {
    return [
      'firstName' => self::decode($order['firstname']),
      'lastName' => self::decode($order['lastname']),
      'email' => self::decode($order['email']),
      'phone' => self::decode($order['telephone']),
    ];
  }

  public function getBillingAddress($order)
  {
    return [
      'firstName'     => self::decode($order['payment_firstname']),
      'lastName'      => self::decode($order['payment_lastname']),
      'address'       => self::decode($order['payment_address_1']),
      'city'          => self::decode($order['payment_city']),
      'postalCode'    => self::decode($order['payment_postcode']),
      'phone'         => self::decode($order['telephone']),
      'countryCode'   => self::decode($order['payment_iso_code_3'])
    ];
  }

  public function getShippingAddress($order)
  {
    if (!$this->cart->hasShipping()) {
      return $this->getBillingAddress($order);
    }

    return [
      'firstName'     => self::decode($order['shipping_firstname']),
      'lastName'      => self::decode($order['shipping_lastname']),
      'address'       => self::decode($order['shipping_address_1']),
      'city'          => self::decode($order['shipping_city']),
      'postalCode'    => self::decode($order['shipping_postcode']),
      'phone'         => self::decode($order['telephone']),
      'countryCode'   => self::decode($order['payment_iso_code_3'])
    ];
  }

  public function getSeller()
  {
    $name = $this->config->get('indodana_checkout_store_name');

    return [
      'name'    => $name,
      'email'   => $this->config->get('indodana_checkout_store_email'),
      'url'     => $this->config->get('indodana_checkout_store_url'),
      'address' => [
        'firstName'   => $name,
        'phone'       => $this->config->get('indodana_checkout_store_phone'),
        'address'     => $this->config->get('indodana_checkout_store_address'),
        'city'        => $this->config->get('indodana_checkout_store_city'),
        'postalCode'  => $this->config->get('indodana_checkout_store_postal_code'),
        'countryCode' => $this->config->get('indodana_checkout_store_country_code'),
      ]
    ];
  }

  public function index()
  {
    $this->loadModel();
    $this->loadLanguage();
    $this->initializeLanguage();

    $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

    $payment_options = $this->getIndodanaCommon()->getInstallmentOptions([
      'totalAmount'    => $this->getTotalAmount($order),
      'discountAmount' => $this->getTotalDiscountAmount($order),
      'shippingAmount' => $this->getTotalShippingAmount($order),
      'taxAmount'      => $this->getTotalTaxAmount($order),
      'products'       => $this->getProducts($order)
    ]);

    $this->formatPaymentOptionsToDefaultCurrency($payment_options);

    $approved_notification_url = $this->url->link('payment/indodana_checkout/notify');
    $cancellation_redirect_url = $this->url->link('payment/indodana_checkout/cancel');
    $back_to_store_url = $this->url->link('checkout/success');

    // DEV MODE
    // $approved_notification_url = 'https://example.com/index.php?route=payment/indodana_checkout/notify';
    // $cancellation_redirect_url = 'https://example.com/index.php?route=payment/indodana_checkout/cancel';
    // $back_to_store_url = 'https://example.com/index.php?route=checkout/success';

    $order_data = $this->getIndodanaCommon()->getCheckoutPayload([
      'merchantOrderId'         => $order['order_id'],
      'totalAmount'             => $this->getTotalAmount($order),
      'discountAmount'          => $this->getTotalDiscountAmount($order),
      'shippingAmount'          => $this->getTotalShippingAmount($order),
      'taxAmount'               => $this->getTotalTaxAmount($order),
      'products'                => $this->getProducts($order),
      'customerDetails'         => $this->getCustomerDetails($order),
      'billingAddress'          => $this->getBillingAddress($order),
      'shippingAddress'         => $this->getShippingAddress($order),
      'approvedNotificationUrl' => $approved_notification_url,
      'cancellationRedirectUrl' => $cancellation_redirect_url,
      'backToStoreUrl'          => $back_to_store_url
    ]);

    $this->data['orderData'] = json_encode($order_data);
    $this->data['paymentOptions'] = $payment_options;
    $this->data['authorization'] = $this->getIndodanaCommon()->getAuthToken();
    $this->data['indodanaBaseUrl'] = $this->getIndodanaCommon()->getBaseUrl();
    $this->data['merchantConfirmPaymentUrl'] = $this->url->link('payment/indodana_checkout/confirmOrder');

    // Starts from Opencart 2.2.0.0, it loads .tpl differently
    // Reference: https://stackoverflow.com/questions/40755573/specific-template-for-category-and-product-page-in-opencart-2-2-0-0
    if (version_compare(VERSION, '2.2.0.0', '<')) {
      return $this->load->view(
        $this->config->get('config_template') . '/template/payment/indodana_checkout_payment.tpl',
        $this->data
      );
    }

    return $this->load->view(
      'payment/indodana_checkout_payment.tpl',
      $this->data
    );
  }

  private function loadLanguage()
  {
    $this->language->load('payment/indodana_checkout');
  }

  private function loadModel()
  {
    $this->load->model('account/order');
    $this->load->model('catalog/product');
    $this->load->model('catalog/category');
    $this->load->model('checkout/order');
    $this->load->model('payment/indodana_checkout');
    $this->load->model('setting/setting');
    $this->load->model('tool/image');
  }

  private function initializeLanguage() {
    $language_keys = [
      'title',
      'text_button_confirm',
      'text_payment_options_name',
      'text_payment_options_monthly_installment',
      'text_payment_options_total_amount',
    ];

    foreach($language_keys as $key) {
      $this->data[$key] = $this->language->get($key);
    }

    $this->document->setTitle($this->data['title']);
  }

  private function formatPaymentOptionsToDefaultCurrency(&$payment_options) {
    $config_currency = $this->config->get('config_currency');
    $session_currency = $this->session->data['currency'];

    foreach ($payment_options as &$payment_option) {
      $monthly_installment = $payment_option['monthlyInstallment'];
      $installment_amount = $payment_option['installmentAmount'];

      $monthly_installment = $this->currency->convert($monthly_installment, $session_currency, $config_currency);
      $installment_amount = $this->currency->convert($installment_amount, $session_currency, $config_currency);

      $payment_option['monthlyInstallment'] = $this->currency->format($monthly_installment, 'IDR');
      $payment_option['installmentAmount'] = $this->currency->format($installment_amount, 'IDR');
    }
  }

  public function confirmOrder()
  {
    $this->load->model('checkout/order');

    $namespace = '[OpencartV2-confirmOrder]';

    $request_body = IndodanaHelper::getRequestBody();

    IndodanaLogger::info(
      sprintf(
        '%s Request body: %s',
        $namespace,
        json_encode($request_body)
      )
    );

    $order_id = $request_body['orderId'];

    $this->model_checkout_order->addOrderHistory(
      $order_id,
      $this->config->get('indodana_checkout_default_order_pending_status_id')
    );

    MerchantResponse::printSuccessResponse($namespace);

    return;
  }

  public function cancel()
  {
    $this->load->model('checkout/order');

    $order_id = $this->session->data['order_id'];

    $this->model_checkout_order->addOrderHistory(
      $order_id,
      $this->config->get('indodana_checkout_default_order_failed_status_id')
    );

    $this->response->redirect($this->url->link('checkout/cart'));
  }

  public function notify()
  {
    $this->load->model('checkout/order');

    // Log request headers
    // -----
    $namespace = '[OpencartV2-notify]';

    $request_headers = IndodanaHelper::getRequestHeaders();

    IndodanaLogger::info(
      sprintf(
        '%s Request headers: %s',
        $namespace,
        json_encode($request_headers)
      )
    );

    // Check whether request authorization is valid
    // -----
    $auth_token = IndodanaHelper::getAuthToken($request_headers, $namespace);

    $is_valid_authorization = $this->getIndodanaCommon()->isValidAuthToken($auth_token);

    if (!$is_valid_authorization) {
      MerchantResponse::printInvalidRequestAuthResponse($namespace);

      return;
    }

    // Log request body
    // -----
    $request_body = IndodanaHelper::getRequestBody();

    IndodanaLogger::info(
      sprintf(
        '%s Request body: %s',
        $namespace,
        json_encode($request_body)
      )
    );

    // Check whether request body is valid
    // -----
    if (!isset($request_body['transactionStatus']) || !isset($request_body['merchantOrderId'])) {
      MerchantResponse::printInvalidRequestBodyResponse($namespace);

      return;
    }

    $transaction_status = $request_body['transactionStatus'];
    $order_id = $request_body['merchantOrderId'];

    $order = $this->model_checkout_order->getOrder($order_id);

    if (!$order) {
      MerchantResponse::printNotFoundOrderResponse(
        $order_id,
        $namespace
      );

      return;
    }

    if (!in_array($transaction_status, IndodanaConstant::getSuccessTransactionStatuses())) {
      MerchantResponse::printInvalidTransactionStatusResponse(
        $transaction_status,
        $order_id,
        $namespace
      );

      return;
    }

    // Handle success order
    // -----
    $this->handleSuccessOrder($order_id);

    MerchantResponse::printSuccessResponse($namespace);

    return;
  }

  private function handleSuccessOrder($order_id)
  {
    $this->model_checkout_order->addOrderHistory(
      $order_id,
      $this->config->get('indodana_checkout_default_order_success_status_id')
    );
  }
}

