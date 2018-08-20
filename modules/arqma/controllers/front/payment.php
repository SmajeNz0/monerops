<?php
include(dirname(__FILE__). '/../../library.php');
class arqmapaymentModuleFrontController extends ModuleFrontController
{
  public $ssl = false;
  public $display_column_left = false;
  /**
   * @see FrontController::initContent()
   */
  private $arqma_daemon;

 public function initContent() {
        parent::initContent();

		global $currency;
        $cart = $this->context->cart;
      	$c = $currency->iso_code;
		$total = $cart->getOrderTotal();
		$amount = $this->changeto($total, $c);
		$actual = $this->retriveprice($c);
		$payment_id  = $this->set_paymentid_cookie();

    $address = Configuration::get('ARQMA_ADDRESS');
		$daemon_address = Configuration::get('ARQMA_WALLET');

		$uri = "arqma:$address?tx_amount=$amount?tx_payment_id=$payment_id";
		$status = "Awaiting Confirmation...";


		$this->arqma_daemon = new Arqma_Library($daemon_address .'/json_rpc',"",""); // example $daemon address 127.0.0.1:18081

		$integrated_address_method = $this->arqma_daemon->make_integrated_address($payment_id);
		$integrated_address = $integrated_address_method["integrated_address"];

		if($this->verify_payment($payment_id, $amount))
		{
			$status = "Your Payment has been confirmed!";
			header("Location: index.php?fc=module&module=arqma&controller=validation");

		}

		$this->context->smarty->assign(array(
            'this_path_ssl'   => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->module->name . '/',
				'address' => $address,
				'amount' => $amount,
				'uri' => $uri,
				'status' => $status,
				'integrated_address' => $integrated_address ));
		$this->setTemplate('payment_execution.tpl');

		echo "<script type='text/javascript'>
				setTimeout(function () { location.reload(true); }, 30000);
			  </script>";
    }


	private function set_paymentid_cookie()
				{
					if(!isset($_COOKIE['payment_id']))
					{
						$payment_id  = bin2hex(openssl_random_pseudo_bytes(8));
						setcookie('payment_id', $payment_id, time()+2700);
					}
					else
						$payment_id = $_COOKIE['payment_id'];
					return $payment_id;
				}

	
	public function retriveprice($c)
				{
								# available currencies at crex24.com : EUR / USD / JPY / CNY / RUB
								if(in_array($c, ['EUR', 'USD', 'JPY' , 'CNY' , 'RUB'], TRUE)){ 
									$trading_fee = 0.10/100; # actual crex24.com trading fee
									$base_url = 'https://api.crex24.com/v2/public/orderBook?instrument=BTC-';
									$arq_btc_req = Tools::file_get_contents('https://api.crex24.com/v2/public/orderBook?instrument=ARQ-BTC');
									$arq_btc_orderbook = json_decode($arq_btc_req, TRUE);
									$btc_ccy_req = Tools::file_get_contents($base_url . $c );
									$btc_ccy_orderbook = json_decode($btc_ccy_req, TRUE);
									$arq_btc_bid = $arq_btc_orderbook['buyLevels']['0']['price'] * (1-$trading_fee); # Sell ARQ for BTC - trading fee
									$btc_ccy_bid = $btc_ccy_orderbook['buyLevels']['0']['price'] * (1-$trading_fee); # Sell BTC for CCY - trading fee
									return $arq_btc_bid * $btc_ccy_bid; # return cross-rate 1 $ARQ = x CCY
									} else {
												return false;
									}
				}
	

	public function changeto($amount, $currency)
	{
		$arq_live_price = $this->retriveprice($currency);
		$new_amount     = $amount / $arq_live_price;
		$rounded_amount = round($new_amount, 12); //the arqma wallet can't handle decimals smaller than 0.000000000001
		return $rounded_amount;
	}

	public function verify_payment($payment_id, $amount)
	{
      /*
       * function for verifying payments
       * Check if a payment has been made with this payment id then notify the merchant
       */

      $amount_atomic_units = $amount * 1000000000000;
      $get_payments_method = $this->arqma_daemon->get_payments($payment_id);
      if(isset($get_payments_method["payments"][0]["amount"]))
      {
		if($get_payments_method["payments"][0]["amount"] >= $amount_atomic_units)
		{
			$confirmed = true;
		}
	  }
	  else
	  {
		  $confirmed = false;
	  }
	  return $confirmed;
  }

}
