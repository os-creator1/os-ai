<?php


namespace App\Library;


use Exception;

class MPesa
{

    protected $config;
    protected $paymentData;

    public function __construct($config, $paymentData)
    {
        $this->config      = $config;
        $this->paymentData = $paymentData;
    }


    //Generate the Bearer Code
    public function getToken()
    {
        $key = "-----BEGIN PUBLIC KEY-----\n";
        $key .= wordwrap($this->config['publicKey'], 160, "\n", true);
        $key .= "\n-----END PUBLIC KEY-----";
        $pk  = openssl_get_publickey($key);
        openssl_public_encrypt($this->config['apiKey'], $token, $pk);

        return 'Bearer '.base64_encode($token);
    }


    //Check if the number is an valid EMOLA Number
    public function checkNumber()
    {
        $numberMS = $this->paymentData['from'];

        $isValid = preg_match('/^(\+|00)?((84|85)\d{7})$/', $numberMS, $matchGroup);
        if ($isValid) {
            return $matchGroup[2];
        }

        return "NOT an valid EMOLA Number";

    }

    /**
     * @throws Exception
     */
    public function submit()
    {
        //Configurations MPESA
        $transPrefix = "MAP";
        if (strlen($transPrefix) > 3 || strpos($transPrefix, '_')) {
            $transPrefix = "ITC";
        }
        $unique_transactionid = strtoupper(substr($transPrefix."I".$this->paymentData['transaction']."I".bin2hex(random_bytes(2)), 0, 22));

     //   $url = "https://api.vm.co.mz:18352/ipg/v1x/c2bPayment/singleStage/";

        $payload = [
                'input_ServiceProviderCode'  => $this->config['serviceProviderCode'],
                'input_CustomerMSISDN'       => "258".$this->checknumber(),
                'input_Amount'               => $this->paymentData['amount'],
                'input_TransactionReference' => $transPrefix.$this->paymentData['transaction'],
                'input_ThirdPartyReference'  => $unique_transactionid,
        ];

        $payload = json_encode($payload);

        //Start CURL POST
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->config['url']);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: '.$this->getToken(),
                'Content-Length: '.strlen($payload),
                'Origin: developer.mpesa.vm.co.mz',
        ]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        //curl_setopt($curl, CURLOPT_TIMEOUT, 400);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);

        $resp = curl_exec($curl);
        //print_r(curl_error($curl));
        curl_close($curl);

        return $resp;

    }

}
