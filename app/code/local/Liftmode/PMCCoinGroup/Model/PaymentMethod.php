<?php
/**
 *
 * @category   Liftmode
 * @package    PMCCoinGroup
 * @copyright  Copyright (c) Dmitry Bashlov <dema50@gmail.com
 * @license    MIT
 */

class Liftmode_PMCCoinGroup_Model_PaymentMethod extends Mage_Payment_Model_Method_Cc
{
    protected $_code = 'pmccoingroup';
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = false;
    protected $_canRefund = false;
    protected $_canVoid = false;
    protected $_canUseCheckout = true;
    protected $_canSaveCC = false;
    protected $_canUseInternal = true;
    protected $_canFetchTransactionInfo = true;

    protected $_formBlockType = 'pmccoingroup/form_cc';
    protected $_infoBlockType = 'pmccoingroup/info_cc';

    const REQUEST_TYPE_AUTH_CAPTURE = 'AUTH_CAPTURE';
    const REQUEST_TYPE_AUTH_ONLY    = 'AUTH_ONLY';
    const REQUEST_TYPE_CAPTURE_ONLY = 'CAPTURE_ONLY';

    /**
     * Assign data to info model instance
     *
     * @param   mixed $data
     * @return  Mage_Payment_Model_Info
     */
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info = $this->getInfoInstance();
        $info->setCcType($data->getCcType())
            ->setCcOwner($data->getCcOwner())
            ->setCcLast4(substr($data->getCcNumber(), -4))
            ->setCcNumber($data->getCcNumber())
            ->setCcCid($data->getCcCid())
            ->setCcExpMonth($data->getCcExpMonth())
            ->setCcExpYear($data->getCcExpYear())
            ->setCcSsIssue($data->getCcSsIssue())
            ->setCcSsStartMonth($data->getCcSsStartMonth())
            ->setCcSsStartYear($data->getCcSsStartYear());
        return $this;
    }

    /**
     * Send authorize request to gateway
     *
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper('pmccoingroup')->__('Invalid amount for authorization.'));
        }

        $payment->setAmount($amount);
        $payment->setAnetTransType(self::REQUEST_TYPE_AUTH_ONLY);

        $data = $this->_doSale($payment);

        $payment->setTransactionId($data['details'][0][''])
                ->setIsTransactionClosed(0);

        return $this;
    }


    /**
     * Return url of payment method
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->getConfigData('apiurl');
    }


    private function _doSale(Varien_Object $payment)
    {
        $order = $payment->getOrder();
        $billing = $order->getBillingAddress();


        $data = array(
            "amount" => (int) $payment->getAmount() * 100, // Yes Decimal Total cents amount with up to 2 decimal places.,
            "wallet_id" => $this->getConfigData('wallet_id'),
            "order_id"=> $order->getIncrementId(),
        );

        $searchCustomerResp = $this->_doGet($this->getURL() . '/v1/customers', array(
            'page' => 1,
            'perPage' => 3,
            'search' => strval($order->getCustomerEmail())
        ));

        if (sizeof($searchCustomerResp["data"]) > 0 && $searchCustomerResp["data"][0]["email"] ===  strval($order->getCustomerEmail())) {
            $data["customer"] = array(
                "id" => $searchCustomerResp["data"][0]["id"]
            );
        } else {
            $data["customer"] = array(
                "name"=> strval($billing->getFirstname()) . ' ' . strval($billing->getLastname()), // Yes String Account holder's first and last name
                "email"  => strval($order->getCustomerEmail()), // Yes String Customer's email address. Must be a valid address. Upon processing of the draft an email will be sent to this address.
                "phone" => strval($billing->getTelephone()),
                "address" => array(
                    "line1" => strval($billing->getStreet(1)),
                    "line2" => strval($billing->getStreet(2)),
                    "country" => strval($billing->getCountry()),
                    "state" => substr(strval($billing->getRegionCode()), 0, 3),// Yes String The state portion of the mailing address associated with the customer's checking account. It must be a valid US state or territory
                    "city" => strval($billing->getCity()), // Yes String The city portion of the mailing address associated with the customer's checking
                    "zipcode" => strval($billing->getPostcode()), // Yes String The zip code portion of the mailing address associated with the customer's checking account. Accepted formats: XXXXX,  XXXXX-XXXX
                ),
                "card" => array(
                    "cardholder_name"=> strval($billing->getFirstname()) . ' ' . strval($billing->getLastname()), // Yes String Account holder's first and last name
                    "number" => $payment->getCcNumber(),
                    "cvc" => $payment->getCcCid(),
                    "expiration" => sprintf('%02d-%02d', $payment->getCcExpMonth(),  substr($payment->getCcExpYear(), -2)),
                    "default" => true,
                    "register" => true
                )
            );
        }

        if (empty($data["customer"]["address"]["state"])) {
            $data["customer"]["address"]["state"] = "UNW";
        }

        list ($resCode, $resData) =  $this->_doPost($this->getURL() . '/v1/charges', json_encode($data));

        return $this->_doValidate($resCode, $resData, json_encode($data));
    }


    private function _doValidate($code, $data = [], $postData)
    {
        if ((int) substr($code, 0, 1) !== 2) {
            $message = $data['message'];
            foreach ($data['errors'] as $field => $error) {
                $message .= sprintf("\r\nthe issue is in %s field - %s\r\n", $field, array_shift($error));
            }

            Mage::log(array('_doValidate--->', $code, $message, $data, $postData), null, 'pmccoingroup.log');
            Mage::throwException(Mage::helper('pmccoingroup')->__("Error during process payment: response code: %s %s", $code, $message));
        }

        return $data;
    }


    private function _doRequest($url, $extReqHeaders = array(), $extOpts = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $reqHeaders = array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Cache-Control: no-cache',
            'Authorization: ' . Mage::helper('core')->decrypt($this->getConfigData('token')),
            'team-id: ' . $this->getConfigData('team_id'),
        );

        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($reqHeaders, $extReqHeaders));

        foreach ($extOpts as $key => $value) {
            curl_setopt($ch, $key, $value);
        }

        $resp = curl_exec($ch);

        list ($respHeaders, $body) = explode("\r\n\r\n", $resp, 2);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!empty($body)) {
            $body = json_decode($body, true);
        }

        foreach (explode("\r\n", $respHeaders) as $hdr) {
            if (preg_match("!Location: http.*\/(.*)\/!", $hdr, $matches)) {
                $body['TransactionId'] = $matches[1];
            }
        }

        if (curl_errno($ch) || curl_error($ch)) {
            Mage::log(array($httpCode, $body, $query, $extReqHeaders, $extOpts, curl_error($ch)), null, 'pmccoingroup.log');
            Mage::throwException(curl_error($ch));
        }

        curl_close($ch);

        return array($httpCode, $body);
    }

    private function _doGet($url, $data)
    {
        if (sizeof($data) > 0) {
            $url .= '?' . http_build_query($data);
        }

        return $this->_doRequest($url, array(
        ), array(
            CURLOPT_RETURNTRANSFER => true,
        ));
    }

    private function _doPost($url, $data)
    {
        return $this->_doRequest($url, array(
            'Content-Length: ' . strlen($data),
        ), array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
        ));
    }
}