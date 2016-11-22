<?php

/*
 * PayPal extension for Yii2.
 * @author David Webb <ravenger@dpwlabs.com>
 * 
 */

namespace dpwlabs\yiipaypal;

use Yii;
use yii\base\Component;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;
use PayPal\Api\Agreement;
use PayPal\Api\Amount;
use PayPal\Api\Currency;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\MerchantPreferences;
use PayPal\Api\Patch;
use PayPal\Api\PatchRequest;
use PayPal\Common\PayPalModel;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentDefinition;
use PayPal\Api\Plan;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Api\PaymentExecution;

class PayPal extends Component {

    private $apiContext;
    public $clientId;
    public $clientSecret;
    public $currency = 'AUD';
    public $config = [
        'mode' => 'sandbox',
        'log.LogEnabled' => true,
        'log.FileName' => '../PayPal.log',
        'log.LogLevel' => 'DEBUG', // PLEASE USE `INFO` LEVEL FOR LOGGING IN LIVE ENVIRONMENTS
        'cache.enabled' => true,
        'cache.FileName' => '../auth.cache/cachefile',
    ];
    private $errors = [];

    public function init() {
        parent::init();
        $this->apiContext = new ApiContext(
                new OAuthTokenCredential($this->clientId, $this->clientSecret)
        );
        $this->apiContext->setConfig($this->config);
    }
    
    public function getErrors() {
        return $this->errors;
    }

    public function listPlans() {
        try {
            $params = ['page_size' => '2'];
            return Plan::all($params, $this->apiContext);
        } catch (\Exception $ex) {
            
        }
        return false;
    }

    public function getPlanByName($name) {
        try {
            $params = ['page_size' => '20', 'status' => 'ACTIVE'];
            $planList = Plan::all($params, $this->apiContext);
            foreach ($planList->getPlans() as $plan) {
                if ($plan->getName() == $name) {
                    return $plan->getId();
                }
            }
        } catch (\Exception $ex) {

        }
        return false;
    }

    public function createRecurringPlan($upfrontAmount, $recurringAmount, $name, $description, $paymentName, $successUrl, $failUrl, $frequency = 'Month', $interval = 1, $type = 'INFINITE') {
        $recurring = new Currency(['value' => $recurringAmount, 'currency' => $this->currency]);
        $upfront = new Currency(['value' => $upfrontAmount, 'currency' => $this->currency]);

        $plan = new Plan();
        $plan->setName($name)
                ->setDescription($description)
                ->setType($type);
        $paymentDefinition = new PaymentDefinition();
        $paymentDefinition->setName($paymentName)
                ->setType('REGULAR')
                ->setFrequency($frequency)
                ->setFrequencyInterval($interval)
                ->setAmount($recurring);

        $merchantPreferences = new MerchantPreferences();
        $merchantPreferences->setReturnUrl($successUrl)
                ->setCancelUrl($failUrl)
                ->setAutoBillAmount('yes')
                ->setInitialFailAmountAction('CONTINUE')
                ->setMaxFailAttempts('0')
                ->setSetupFee($upfront);
        $plan->setPaymentDefinitions([$paymentDefinition]);
        $plan->setMerchantPreferences($merchantPreferences);

        try {
            $createdPlan = $plan->create($this->apiContext);
            // Activate the created plan.
            $patch = new Patch();
            $value = new PayPalModel('{
	       "state":"ACTIVE"
	     }');

            $patch->setOp('replace')
                    ->setPath('/')
                    ->setValue($value);
            $patchRequest = new PatchRequest();
            $patchRequest->addPatch($patch);
            $createdPlan->update($patchRequest, $this->apiContext);
            return $createdPlan->getId();
        } catch (\Exception $ex) {
            $this->errors[] = $ex->getMessage();
            return false;
        }
    }

    public function createAgreement($planId, $name, $description, $startDateStr) {
        $payer = new Payer();
        $payer->setPaymentMethod('paypal');
        $refPlan = new Plan();
        $refPlan->setId($planId);
        $agreement = new Agreement();
        $agreement->setName($name)
                ->setDescription($description)
                ->setStartDate($startDateStr)
                ->setPlan($refPlan)
                ->setPayer($payer);
        try {
            return $agreement->create($this->apiContext);
        } catch (\Exception $ex) {
            $this->errors[] = $ex->getMessage();
        }
        return false;
    }

    public function createSimplePayment($itemPrice, $itemName, $description, $successUrl, $failUrl, $tax = 0, $shipping = 0) {
        $items = [];
        $items[] = ['price' => $itemPrice, 'name' => $itemName];
        return $this->createPaymentFromArray($items, $description, $successUrl, $failUrl, $tax, $shipping);
    }
    
    public function createPaymentFromArray(array $items, $description, $successUrl, $failUrl, $tax = 0, $shipping = 0) {
        $payer = new Payer();
        $payer->setPaymentMethod('paypal');
        $item_list = new ItemList();
        $subtotal = 0;
        foreach ($items as $item) {
            $item1 = new Item();
            $item1->setName($item['name'])
                    ->setCurrency($this->currency)
                    ->setQuantity(1)
                    ->setPrice($item['price']);
            $item_list->addItem($item1);
            $subtotal += $item['price'];
        }
        $details = new Details();
        $details->setSubtotal($subtotal);
        if ($tax > 0) {
            $details->setTax($tax);
        } else {
            $tax = 0;
        }
        if ($shipping > 0) {
            $details->setShipping($shipping);
        } else {
            $shipping = 0;
        }
        $amount = new Amount();
        $amount->setCurrency($this->currency)
                ->setTotal($subtotal + $tax + $shipping)
                ->setDetails($details);

        $transaction = new Transaction();
        $transaction->setAmount($amount);
        $transaction->setItemList($item_list);
        $transaction->setDescription($description);

        $redirect_urls = new RedirectUrls();
        $redirect_urls->setReturnUrl($successUrl)
                ->setCancelUrl($failUrl);

        $payment = new Payment();
        $payment->setIntent('sale')
                ->setPayer($payer)
                ->setRedirectUrls($redirect_urls)
                ->setTransactions([$transaction]);

        try {
            return $payment->create($this->apiContext);
        } catch (\PayPal\Exception\PayPalConnectionException $ex) {
            $this->errors[] = $ex->getMessage();
        } catch (\Exception $ex) {
            $this->errors[] = $ex->getMessage();
        }
        return false;
    }

    public function confirmPayment($paymentId) {
        try {
            $payment = Payment::get($paymentId, $this->apiContext);
            $execution = new PaymentExecution();
            $execution->setPayerId(Yii::$app->request->get('PayerID'));
            // Execute payment
            $result = $payment->execute($execution, $this->apiContext);
            return $result->getState() == 'approved';
        } catch (\Exception $ex) {
            
        }
        return false;
    }
    
    public function confirmSubscription($token) {
        $agreement = new Agreement();
        try {
            $agreement->execute($token, $this->apiContext);
            $confirmed = Agreement::get($agreement->getId(), $this->apiContext);
            return strcasecmp($confirmed->getState(), 'ACTIVE') == 0;
        } catch (\Exception $ex) {
            
        }
        return false;
    }
}
