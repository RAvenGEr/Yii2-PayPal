<?php

/*
 * PayPal extension for Yii2.
 * @author David Webb <ravenger@dpwlabs.com>
 * 
 */

namespace dpwlabs\yiipaypal;

use Yii;
use yii\component\Component;

class PayPal extends Component {

    private $apiContext;
    public $clientId;
    public $clientSecret;
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

    public function createMonthlyPlan($upfrontAmount, $recurringAmount, $name, $description, $paymentName, $successUrl, $failUrl, $type = 'INFINITE') {
        $recurring = new Currency(array('value' => $recurringAmount, 'currency' => 'AUD'));
        $upfront = new Currency(array('value' => $upfrontAmount, 'currency' => 'AUD'));

        $plan = new Plan();
        $plan->setName($name)
                ->setDescription($description)
                ->setType($type);
        $paymentDefinition = new PaymentDefinition();
        $paymentDefinition->setName($paymentName)
                ->setType('REGULAR')
                ->setFrequency('Month')
                ->setFrequencyInterval('1')
                ->setAmount($recurring);

        $merchantPreferences = new MerchantPreferences();
        $merchantPreferences->setReturnUrl($successUrl)
                ->setCancelUrl($failUrl)
                ->setAutoBillAmount('yes')
                ->setInitialFailAmountAction('CONTINUE')
                ->setMaxFailAttempts('0')
                ->setSetupFee($upfront);
        $plan->setPaymentDefinitions(array($paymentDefinition));
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
    
    public function createSimplePayment($itemPrice, $itemTax, $itemName, $description, $successUrl, $failUrl) {
        $payer = new Payer();
        $payer->setPaymentMethod('paypal');
        $item1 = new Item();
        $item1->setName($itemName)
                ->setCurrency('AUD')
                ->setQuantity(1)
                ->setPrice($itemPrice);
        
        $item_list = new ItemList();
        $item_list->addItem($item1);

        $details = new Details();
        if ($itemTax > 0) {
            $details->setTax($itemTax);
        }
        $amount = new Amount();
        $amount->setCurrency('AUD')
                ->setTotal($itemPrice)
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
            return $payment->create($this->apiCcontext);
        } catch (\PayPal\Exception\PayPalConnectionException $ex) {
            $this->errors[] = $ex->getMessage();
        } catch (\Exception $ex) {
            $this->errors[] = $ex->getMessage();
        }
        return false;
    }

}
