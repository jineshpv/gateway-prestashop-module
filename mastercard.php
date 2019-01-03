<?php
/**
 * Copyright (c) On Tap Networks Limited.
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

define('MPGS_ISO3_COUNTRIES', include dirname(__FILE__).'/iso3.php');

require_once(dirname(__FILE__) . '/vendor/autoload.php');
require_once(dirname(__FILE__) . '/gateway.php');

class Mastercard extends PaymentModule
{
    const MPGS_API_VERSION = '50';

    /**
     * @var string
     */
    protected $_html = '';

    /**
     * @var string
     */
    protected $controllerAdmin;

    /**
     * @var array
     */
    protected $_postErrors = array();

    /**
     * Mastercard constructor.
     */
    public function __construct()
    {
        $this->name = 'mastercard';
        $this->tab = 'payments_gateways';

        $this->version = '1.0.0';
        if (!defined('MPGS_VERSION')) {
            define('MPGS_VERSION', $this->version);
        }

        $this->author = 'OnTap Networks Limited';
        $this->need_instance = 1;
        $this->controllers = array('payment', 'validation');
        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => _PS_VERSION_);

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;
        parent::__construct();

        $this->controllerAdmin = 'AdminMpgs';
        $this->displayName = $this->l('MasterCard');
        $this->description = $this->l('MasterCard Payment Module for Prestashop');

//        $this->limited_countries = array('FR');
//        $this->limited_currencies = array('EUR');
//        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
//        $helper->token = Tools::getAdminTokenLite('AdminModules');
    }

    /**
     * @param string $iso2country
     * @return string
     */
    public function iso2ToIso3($iso2country)
    {
        return MPGS_ISO3_COUNTRIES[$iso2country];
    }

    /**
     * @return string
     */
    public static function getApiVersion()
    {
        return self::MPGS_API_VERSION;
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        if (extension_loaded('curl') == false)
        {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        if (!$this->installOrderState()) {
            return false;
        }

        // Install admin tab
        if (!$this->installTab()) {
            return false;
        }

        return parent::install() &&
            $this->registerHook('paymentOptions') &&
            $this->registerHook('displayAdminOrderLeft') &&
            $this->registerHook('displayBackOfficeOrderActions');
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        Configuration::deleteByName('mpgs_hc_title');
        Configuration::deleteByName('mpgs_hs_title');

//        Configuration::deleteByName('MPGS_OS_PAYMENT_WAITING');
//        Configuration::deleteByName('MPGS_OS_AUTHORIZED');

        $this->unregisterHook('paymentOptions');
        $this->unregisterHook('displayBackOfficeOrderActions');
        $this->unregisterHook('displayAdminOrderLeft');

        $this->uninstallTab();

        return parent::uninstall();
    }

    /**
     * @param $params
     */
    public function hookDisplayBackOfficeOrderActions($params)
    {
        // noop
    }

    /**
     * @return int
     */
    private function installTab()
    {
        $tab = new Tab();
        $tab->class_name = $this->controllerAdmin;
        $tab->active = 1;
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $this->name;
        }
        $tab->id_parent = -1;
        $tab->module = $this->name;

        return $tab->add();
    }

    /**
     * @return bool
     */
    private function uninstallTab()
    {
        $id_tab = (int)Tab::getIdFromClassName($this->controllerAdmin);
        $tab = new Tab($id_tab);
        if (Validate::isLoadedObject($tab)) {
            return $tab->delete();
        }
    }

    /**
     * @return bool
     */
    public function installOrderState()
    {
        if (!Configuration::get('MPGS_OS_PAYMENT_WAITING')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('MPGS_OS_PAYMENT_WAITING')))) {

            $order_state = new OrderState();
            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Awaiting Payment';
            }
            $order_state->send_email = false;
            $order_state->color = '#4169E1';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;
            $order_state->paid = false;
            if ($order_state->add()) {
                $source = _PS_ROOT_DIR_.'/img/os/10.gif';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int) $order_state->id.'.gif';
                copy($source, $destination);
            }

            Configuration::updateValue('MPGS_OS_PAYMENT_WAITING', (int) $order_state->id);
        }
        if (!Configuration::get('MPGS_OS_AUTHORIZED')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('MPGS_OS_AUTHORIZED')))) {

            $order_state = new OrderState();
            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Payment Authorized';
                $order_state->template[$language['id_lang']] = 'payment';
            }
            $order_state->send_email = true;
            $order_state->color = '#4169E1';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = true;
            $order_state->paid = true;
            $order_state->invoice = false;
            if ($order_state->add()) {
                $source = _PS_ROOT_DIR_.'/img/os/10.gif';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int) $order_state->id.'.gif';
                copy($source, $destination);
            }

            Configuration::updateValue('MPGS_OS_AUTHORIZED', (int) $order_state->id);
        }

        return true;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */

        if (((bool)Tools::isSubmit('submitMastercardModule')) == true) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        }

        $this->context->controller->addJS($this->_path.'/views/js/back.js');
        $this->context->smarty->assign('module_dir', $this->_path);
        $this->_html .= $this->display($this->local_path, 'views/templates/admin/configure.tpl');
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    /**
     * @return void
     */
    protected function _postValidation()
    {
        if (!Tools::getValue('mpgs_api_url')) {
            if (!Tools::getValue('mpgs_api_url_custom')) {
                $this->_postErrors[] = $this->l('Custom API Endpoint is required.');
            }
        }
        if (Tools::getValue('mpgs_mode') === "1") {
            if (!Tools::getValue('mpgs_merchant_id')) {
                $this->_postErrors[] = $this->l('Merchant ID is required.');
            }
            if (!Tools::getValue('mpgs_api_password')) {
                $this->_postErrors[] = $this->l('API password is required.');
            }
            if (!Tools::getValue('mpgs_webhook_secret')) {
                $this->_postErrors[] = $this->l('Webhook Secret is required.');
            }
        } else {
            if (!Tools::getValue('test_mpgs_merchant_id')) {
                $this->_postErrors[] = $this->l('Test Merchant ID is required.');
            }
            if (!Tools::getValue('test_mpgs_api_password')) {
                $this->_postErrors[] = $this->l('Test API password is required.');
            }
            // In test mode, the Secret is not required
//            if (!Tools::getValue('test_mpgs_webhook_secret')) {
//                $this->_postErrors[] = $this->l('Test Webhook Secret is required.');
//            }
        }
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMastercardModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getAdminFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array(
            $this->getAdminGeneralSettingsForm(),
            $this->getAdminHostedCheckoutForm(),
            $this->getAdminHostedSessionForm(),
        ));
    }

    /**
     * @return array
     */
    protected function getApiUrls()
    {
        return array(
            'eu-gateway.mastercard.com' => $this->l('eu-gateway.mastercard.com'),
            'ap-gateway.mastercard.com' => $this->l('ap-gateway.mastercard.com'),
            'na-gateway.mastercard.com' => $this->l('na-gateway.mastercard.com'),
            'mtf.gateway.mastercard.com' => $this->l('mtf.gateway.mastercard.com'),
            '' => $this->l('Other'),
        );
    }

    /**
     * @return array
     */
    protected function getAdminFormValues()
    {
        $hcTitle = array();
        $hsTitle = array();
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            $value = Tools::getValue(
                'mpgs_hc_title_' . $lang['id_lang'],
                Configuration::get('mpgs_hc_title', $lang['id_lang'])
            );
            $hcTitle[$lang['id_lang']] = $value ? $value : $this->l('MasterCard Hosted Checkout');

            $value = Tools::getValue(
                'mpgs_hs_title_' . $lang['id_lang'],
                Configuration::get('mpgs_hs_title', $lang['id_lang'])
            );
            $hsTitle[$lang['id_lang']] = $value ? $value : $this->l('MasterCard Hosted Session');
        }

        return array(
            'mpgs_hc_active' => Tools::getValue('mpgs_hc_active', Configuration::get('mpgs_hc_active')),
            'mpgs_hc_title' => $hcTitle,
            'mpgs_hc_theme' => Tools::getValue('mpgs_hc_theme', Configuration::get('mpgs_hc_theme')),
            'mpgs_hc_show_billing' => Tools::getValue('mpgs_hc_show_billing', Configuration::get('mpgs_hc_show_billing')),
            'mpgs_hc_show_email' => Tools::getValue('mpgs_hc_show_email', Configuration::get('mpgs_hc_show_email')),

            'mpgs_hs_active' => Tools::getValue('mpgs_hs_active', Configuration::get('mpgs_hs_active')),
            'mpgs_hs_title' => $hsTitle,

            'mpgs_mode' => Tools::getValue('mpgs_mode', Configuration::get('mpgs_mode')),
            'mpgs_api_url' => Tools::getValue('mpgs_api_url', Configuration::get('mpgs_api_url')),
            'mpgs_api_url_custom' => Tools::getValue('mpgs_api_url_custom', Configuration::get('mpgs_api_url_custom')),

            'mpgs_merchant_id' => Tools::getValue('mpgs_merchant_id', Configuration::get('mpgs_merchant_id')),
            'mpgs_api_password' => Tools::getValue('mpgs_api_password', Configuration::get('mpgs_api_password')),
            'mpgs_webhook_secret' => Tools::getValue('mpgs_webhook_secret', Configuration::get('mpgs_webhook_secret')),

            'test_mpgs_merchant_id' => Tools::getValue('test_mpgs_merchant_id', Configuration::get('test_mpgs_merchant_id')),
            'test_mpgs_api_password' => Tools::getValue('test_mpgs_api_password', Configuration::get('test_mpgs_api_password')),
            'test_mpgs_webhook_secret' => Tools::getValue('test_mpgs_webhook_secret', Configuration::get('test_mpgs_webhook_secret')),
        );
    }

    /**
     * @return array
     */
    protected function getAdminHostedCheckoutForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Payment Method Settings - Hosted Checkout'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enabled'),
                        'name' => 'mpgs_hc_active',
                        'is_bool' => true,
                        'desc' => '',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => false,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => true,
                                'label' => $this->l('Disabled'),
                            )
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Title'),
                        'name' => 'mpgs_hc_title',
                        'required' => true,
                        'lang' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Theme'),
                        'name' => 'mpgs_hc_theme',
                        'required' => false
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Billing Address display'),
                        'name' => 'mpgs_hc_show_billing',
                        'options' => array(
                            'query' => array(
                                array('id' => 'HIDE', 'name' => 'Hide'),
                                array('id' => 'MANDATORY', 'name' => 'Mandatory'),
                                array('id' => 'OPTIONAL', 'name' => 'Optional'),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Email Address display'),
                        'name' => 'mpgs_hc_show_email',
                        'options' => array(
                            'query' => array(
                                array('id' => 'HIDE', 'name' => 'Hide'),
                                array('id' => 'MANDATORY', 'name' => 'Mandatory'),
                                array('id' => 'OPTIONAL', 'name' => 'Optional'),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            )
        );
    }

    /**
     * @return array
     */
    protected function getAdminHostedSessionForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Payment Method Settings - Hosted Session'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enabled'),
                        'name' => 'mpgs_hs_active',
                        'is_bool' => true,
                        'desc' => '',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => false,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => true,
                                'label' => $this->l('Disabled'),
                            )
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Title'),
                        'name' => 'mpgs_hs_title',
                        'required' => true,
                        'lang' => true,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            )
        );
    }

    /**
     * @return array
     */
    protected function getAdminGeneralSettingsForm()
    {
        $apiOptions = array();
        $c = 0;
        foreach ($this->getApiUrls() as $url => $label) {
            $apiOptions[] = array(
                'id' => 'api_' . $c,
                'value' => $url,
                'label' => $label,
            );
            $c++;
        }

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('General Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Live mode'),
                        'name' => 'mpgs_mode',
                        'is_bool' => true,
                        'desc' => $this->l('Use this module in live mode'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => false,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => true,
                                'label' => $this->l('Disabled'),
                            )
                        ),
                    ),
                    array(
                        'type' => 'radio',
                        'name' => 'mpgs_api_url',
                        'desc' => $this->l(''),
                        'label' => $this->l('API Endpoint'),
                        'values' => $apiOptions
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Custom API Endpoint'),
                        'name' => 'mpgs_api_url_custom',
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Merchant ID'),
                        'name' => 'mpgs_merchant_id',
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('API Password'),
                        'name' => 'mpgs_api_password',
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Webhook Secret'),
                        'name' => 'mpgs_webhook_secret',
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Test Merchant ID'),
                        'name' => 'test_mpgs_merchant_id',
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Test API Password'),
                        'name' => 'test_mpgs_api_password',
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Test Webhook Secret'),
                        'name' => 'test_mpgs_webhook_secret',
                        'required' => false
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getAdminFormValues();

        // Handles normal fields
        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }

        // Handles translated fields
        $translatedFields = array(
            'mpgs_hc_title',
            'mpgs_hs_title'
        );
        $languages = Language::getLanguages(false);
        foreach ($translatedFields as $field) {
            $translatedValues = array();
            foreach ($languages as $lang) {
                if (Tools::getIsset($field.'_'.$lang['id_lang'])) {
                    $translatedValues[$lang['id_lang']] = Tools::getValue($field . '_' . $lang['id_lang']);
                }
            }
            Configuration::updateValue($field, $translatedValues);
        }

        $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
    }

    /**
     * @param $params
     * @return string
     */
    public function hookDisplayAdminOrderLeft($params)
    {
        if ($this->active == false) {
            return '';
        }

        $order = new Order($params['id_order']);
        if ($order->payment != $this->displayName) {
            return '';
        }

        $isAuthorized = $order->current_state == Configuration::get('MPGS_OS_AUTHORIZED');
        $canVoid = $isAuthorized;
        $canCapture = $isAuthorized;
        $canRefund = $order->current_state == Configuration::get('PS_OS_PAYMENT');

        $this->smarty->assign(array(
            'module_dir' => $this->_path,
            'order' => $order,
            'can_void' => $canVoid,
            'can_capture' => $canCapture,
            'can_refund' => $canRefund,
            'is_authorized' => $isAuthorized,
        ));

        if (!$canRefund && !$canCapture && !$canVoid) {
            return '';
        }

        return $this->display(__FILE__, 'views/templates/hook/order_actions.tpl');
    }

    /**
     * @param $params
     * @return array
     * @throws SmartyException
     * @throws Exception
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return array();
        }

        $this->context->smarty->assign(array(
            'mpgs_config' => array(
                'merchant_id' => $this->getConfigValue('mpgs_merchant_id'),
                'amount' => $this->context->cart->getOrderTotal(),
                'currency' => $this->context->currency->iso_code,
                'checkout_component_url' => $this->getJsComponent(),
                'order_id' => (int)$this->context->cart->id,
            ),
        ));

        $methods = array();

        if (Configuration::get('mpgs_hc_active')) {
            $methods[] = $this->getHostedCheckoutPaymentOption();
        }

        if (Configuration::get('mpgs_hs_active')) {
            $methods[] = $this->getHostedSessionPaymentOption();
        }

        return $methods;
    }

    /**
     * @param $field
     * @return string|false
     */
    public function getConfigValue($field)
    {
        $testPrefix = '';
        if (!Configuration::get('mpgs_mode')) {
            $testPrefix = 'test_';
        }

        return Configuration::get($testPrefix . $field);
    }

    /**
     * @return PaymentOption
     */
    protected function getHostedSessionPaymentOption()
    {
        $option = new PaymentOption();
        $option
            ->setModuleName($this->name . '_hs')
            ->setCallToActionText(Configuration::get('mpgs_hs_title', $this->context->language->id));

        return $option;
    }

    /**
     * @return PaymentOption
     * @throws SmartyException
     */
    protected function getHostedCheckoutPaymentOption()
    {
        $option = new PaymentOption();
        $option
            ->setModuleName($this->name . '_hc')
            //$this->l('MasterCard Hosted Checkout', array(), 'Modules.Mastercard.Admin')
            ->setCallToActionText(Configuration::get('mpgs_hc_title', $this->context->language->id))
            ->setAdditionalInformation($this->context->smarty->fetch('module:mastercard/views/templates/front/methods/hostedcheckout.tpl'))
            ->setForm($this->generateHostedCheckoutForm());

        return $option;
    }

    /**
     * @return string
     * @throws SmartyException
     */
    protected function generateHostedCheckoutForm()
    {
        $this->context->smarty->assign(array(
            'hostedcheckout_action_url' => $this->context->link->getModuleLink($this->name, 'hostedcheckout', array(), true),
            'hostedcheckout_cancel_url' => $this->context->link->getModuleLink($this->name, 'hostedcheckout', array('cancel' => 1), true),
        ));
        return $this->context->smarty->fetch('module:mastercard/views/templates/front/methods/hostedcheckout/form.tpl');
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getApiEndpoint()
    {
        $endpoint = Configuration::get('mpgs_api_url');
        if (!$endpoint) {
            $endpoint = Configuration::get('mpgs_api_url_custom');
        }

        if (!$endpoint) {
            throw new Exception("API endpoint not specified.");
        }

        return $endpoint;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getJsComponent()
    {
        return 'https://'. $this->getApiEndpoint() . '/checkout/version/' . $this->getApiVersion() . '/checkout.js';
    }

    /**
     * @return string
     */
    public function getWebhookUrl()
    {
        // SSH tunnel
        // curl http://li301-231.members.linode.com/en/module/mastercard/webhook
        // ssh -nNTR 0.0.0.0:80:localhost:80 root@178.79.163.231 -vvv
        return 'http://li301-231.members.linode.com/en/module/mastercard/webhook';
//        return $this->context->link->getModuleLink($this->name, 'webhook', array(), true);
    }

    /**
     * @param string $type
     * @param Order order
     * @return bool|string
     */
    public function findTxnId($type, $order)
    {
        $authTxn = $this->findTxn($type, $order);
        if (!$authTxn) {
            return $authTxn;
        }

        list($mark, $txnId) = explode('-', $authTxn->transaction_id, 2);
        return $txnId;
    }

    /**
     * @param string $type
     * @param Order $order
     * @return bool|OrderPayment
     */
    public function findTxn($type, $order)
    {
        foreach ($order->getOrderPayments() as $payment) {
            if (stripos($payment->transaction_id, $type . '-') !== false) {
                return $payment;
            }
        }
        return false;
    }
}
