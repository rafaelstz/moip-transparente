<?php
/**
 * Transparente - Transparente Payment Module
 *
 * @title      Magento -> Custom Payment Module for Transparente (Brazil)
 * @category   Payment Gateway
 * @package    MOIP_Transparente
 * @author     Moip solucoes web ldta
 * @copyright  Copyright (c) 2010 Transparente Pagamentos S/A
 * @license    Autorizado o uso por tempo indeterminado
 */
class MOIP_Transparente_StandardController extends Mage_Core_Controller_Front_Action {
	public function getStandard() {
		return Mage::getSingleton('transparente/standard');
	}

	protected function _expireAjax() {
		if (!Mage::getSingleton('checkout/session')->getQuote()->hasItems()) {
			$this->getResponse()->setHeader('HTTP/1.1', '403 Session Expired');
			exit;
		}
	}
	
	public function generateToken($xml) {
		$session = Mage::getSingleton('checkout/session');
		
		$documento = 'Content-Type: application/xml; charset=utf-8';
		 if (Mage::getSingleton('transparente/standard')->getConfigData('ambiente') == "teste") { 
	          $url = "https://desenvolvedor.moip.com.br/sandbox/ws/alpha/EnviarInstrucao/Unica";
	        $header = "Authorization: Basic " . base64_encode(MOIP_Transparente_Model_Api::TOKEN_TEST . ":" . MOIP_Transparente_Model_Api::KEY_TEST);
	      }
	          else {
	              $url = "https://www.moip.com.br/ws/alpha/EnviarInstrucao/Unica";
	        $header = "Authorization: Basic " . base64_encode(MOIP_Transparente_Model_Api::TOKEN_PROD . ":" . MOIP_Transparente_Model_Api::KEY_PROD);
	      }
	      $result = array();
	      $ch = curl_init(); 
			curl_setopt($ch, CURLOPT_URL,$url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 500);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array($header, $documento));
			$res = curl_exec($ch);
			if($res === false)
				{
				    
				    Mage::log(curl_error($ch), null, 'MOIP_Transparente.log', true);
				    Mage::log($xml, null, 'MOIP_Transparente.log', true);
				    $this->generateToken($xml);
				}
		 	curl_close($ch); 


		 	 $res = simplexml_load_string($res);
		 	 if($res->Resposta->Status == "Sucesso"){
		 	 	$result['status'] = (string)$res->Resposta->Status;
		 	 	$result['token'] = (string)$res->Resposta->Token;
		 	 	Mage::log($result['token'], null, 'MOIP_Transparente.log', true);
        		Mage::log($xml, null, 'MOIP_Transparente.log', true);
		 	 	$session->setResult_decode($result);
		 	 	
		 	 	return $result;
		 	 	}
		 	 else {
		 	 	$result['status'] = (string)$res->Resposta->Status;
		 	 	$result['erro'] = (string)$res->Resposta->Erro;
		 	 	Mage::log("erro em status do server transparente".$result['erro'], null, 'MOIP_Transparente.log', true);
        		Mage::log($xml, null, 'MOIP_Transparente.log', true);
		 	 	return $result;
		 	 }

		    
    }
	public function redirectAction() {
		$session = Mage::getSingleton('checkout/session');
		$getSaltes = Mage::getModel('sales/order');
		$standard = $this->getStandard();
		$fields = $session->getTransparenteFields();
		$pgtoArray = $session->getPgtoArray();
		$api = Mage::getModel('transparente/api');
		$api->setAmbiente($standard->getConfigData('ambiente'));
		$pedido_send = $api->generatePedido($fields, $pgtoArray);
		$gettoken = $this->generateToken($pedido_send);
		$session->setCurrent_order($getSaltes->load($session->getLastOrderId()));
		$session->setPgtoarry($pgtoArray);
		$session->setClient_array($fields);
		$this->loadLayout();
		#$this->getLayout()->getBlock('content')->append($this->getLayout()->createBlock('MOIP_Transparente_Block_Standard_Redirect'));
		if($pgtoArray['forma_pagamento'] == "BoletoBancario"){
			$this->getLayout()->getBlock('content')->append('transparente.boleto');
		}
		elseif ($pgtoArray['forma_pagamento'] == "DebitoBancario") {
			$this->getLayout()->getBlock('content')->append('transparente.transferencia');
		}
		elseif ($pgtoArray['forma_pagamento'] == "CartaoCredito") {
			$this->getLayout()->getBlock('content')->append('transparente.cartao');
		}
		$this->renderLayout();
	}
	

	public function cancelAction() {
		$session = Mage::getSingleton('checkout/session');
		$session->setQuoteId($session->getTransparenteStandardQuoteId(true));

		if ($session->getLastRealOrderId()) {
			$order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
			if ($order->getId()) {
				$order->cancel()->save();
			}
		}
		$this->_redirect('checkout/cart');
	}

	public function successAction() {
		$standard = $this->getStandard();
		$naexecuta = "";
		$validacao = $this->getRequest()->getParams();
		if($validacao['validacao'] == $standard->getConfigData('validador_retorno')){
				$data = $this->getRequest()->getPost();
				$login = $standard->getConfigData('conta_transparente')."_";
				$data_transparente = trim($data['id_transacao']);
				$order_magento = strpos($data_transparente, $login);
				$order_magento = substr($data_transparente, strpos($data_transparente, "_") + 1);
				$model = Mage::getModel('transparente/write');
				$model->load($order_magento, 'key_payment');
				$order = Mage::getModel('sales/order')->load($order_magento);
				$id_order = $order->getId();
				$states_atual = $order->getStatus();
				if($states_atual == "processing"){
					$naexecuta = 1;
				}
				if($states_atual == "complete"){
					$naexecuta = 1;
				}
				if($states_atual == "closed"){
					$naexecuta = 1;
				}
				if($states_atual == 'canceled' && $data['status_pagamento']==5){
					$naexecuta = 1;
				}
				Mage::log("Nasp acionou para o pedido ".$order_magento. " - Status - " .$data['status_pagamento'], null, 'MOIP_Transparente.log', true);
				if ($order->isCanceled() && $data['status_pagamento'] != "5") {
					if (Mage::helper('sales/reorder')->canReorder($order)) {
						$order->setState(Mage_Sales_Model_Order::STATE_NEW);
						$produtos = array();
						foreach ($order->getAllItems() as $item) {
							$item->setQtyCanceled(1);
							$item->save();
							$stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($item->getProductId());
							$stockItem->subtractQty($item->getQtyOrdered());
							$stockItem->setIsInStock(true)->setStockStatusChangedAutomaticallyFlag(true);
							$stockItem->save();
							$produtos = $item->getProductId();
							$product = Mage::getModel('catalog/product')->loadByAttribute('sku', $produtos);
							$qty = $item->getQtyOrdered();
							$rowTotal = $item->getPrice();
							$orderItem = Mage::getModel('sales/order_item')
									->setStoreId($order->getStore()->getStoreId())
									->setQuoteItemId(NULL)
									->setQuoteParentItemId(NULL)
									->setProductId($item->getId())
									->setProductType($item->getTypeId())
									->setQtyBackordered(NULL)
									->setTotalQtyOrdered($qty)
									->setQtyOrdered($qty)
									->setName($item->getName())
									->setSku($item->getSku())
									->setPrice($item->getPrice())
									->setBasePrice($item->getPrice())
									->setOriginalPrice($item->getPrice())
									->setRowTotal($rowTotal)
									->setBaseRowTotal($rowTotal)
									->setOrder($order);
							$orderItem->save();
						}
						$order->save();
					}
				}
				switch ($data['status_pagamento']) {
					case "1":
							if($states_atual != 'processing'){
								$state = Mage_Sales_Model_Order::STATE_PROCESSING;
								$status = 'processing';
								$comment = $this->getStatusPagamentoTransparente($status).' - Codig. Transparente: '.$data['cod_moip'];
								$invoice = $order->prepareInvoice();
								if ($this->getStandard()->canCapture())
								{
										$invoice->register()->capture();
								}
								Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder())->save();
								$invoice->sendEmail();
								$invoice->setEmailSent(true);
								$invoice->save();
							} else {
								$naexecuta = 1;
							}
					break;
					case "2":
						$state = Mage_Sales_Model_Order::STATE_HOLDED;
						$status = 'holded';
						$comment = $this->getStatusPagamentoTransparente($data['status_pagamento']).' - Codig. Transparente: '.$data['cod_moip'];
					break;
					case "3":
							if($states_atual != 'processing' && $states_atual != 'holded'){
								$state = Mage_Sales_Model_Order::STATE_HOLDED;
								$status = 'holded';
								$comment = $this->getStatusPagamentoTransparente($data['status_pagamento']).' - Codig. Transparente: '.$data['cod_moip'];
							} else {
								$naexecuta = 1;
							}
					break;
					case "5":
						$state = Mage_Sales_Model_Order::STATE_CANCELED;
						$status = 'canceled';
						$comment = $this->getStatusPagamentoTransparente($data['status_pagamento']).' - Codig. Transparente: '.$data['cod_moip'].' - Motivo: '.utf8_encode($data['classificacao']);
						$order->cancel();
					break;
					case "6":
						$state = Mage_Sales_Model_Order::STATE_HOLDED;
						$status = 'holded';
						$comment = $this->getStatusPagamentoTransparente($data['status_pagamento']).' - Codig. Transparente: '.$data['cod_moip'];
					break;
				}
				if($naexecuta != 1){
					$order->setState($state, $status, $comment, $notified = true, $includeComment = true);
					$order->save();
					if(Mage::getStoreConfig('payment/moip_transparente_standard/notificar_cliente') == 1){
						if( $order->getStatus() != 'pending' && $data['status_pagamento'] != 2){
							$order->sendOrderUpdateEmail(true, $comment);
							}
						}
					echo 'Processo de retorno concluido para o pedido #'.$id_order.' Status '.$status;
					Mage::log("Cliente do pedido ".$id_order. " - Status - " .$states_atual, null, 'MOIP_Transparente.log', true);
				}
				else {
					Mage::log("Nao atualizado transparente Cliente do pedido ".$id_order. " - Status - " .$states_atual , null, 'MOIP_Transparente.log', true);
				}
		}
	}

	private function getNomePagamento($param) {
		$nome = "";
		switch ($param) {
		case "BoletoBancario":
			$nome = "Boleto Bancário";
			break;
		case "DebitoBancario":
			$nome = "Debito Bancário";
			break;
		case "CartaoCredito":
			$nome = "Cartão de Crédito";
			break;
		default:
			$nome ="meio";
			break;
		}
		return $nome;
	}

	private function getStatusPagamentoTransparente($param) {
		switch ($param) {
			case "1":
				$param = "Pagamento Autorizado";
				break;
			case "2":
				$param = "Pagamento Iniciado";
				break;
			case "3":
				$param = "Boleto Impresso";
				break;
			case "4":
				$param = "Pagamento Concluido";
				break;
			case "5":
				$param = "Pagamento Cancelado";
				break;
			case "6":
				$param = "Pagamento em análise";
				break;
			case "7":
				$param = "Pagamento Reembolsado";
				break;
			case "8":
				$param = "Pagamento Revertido pela Operadora";
				break;
			default:
				$param = "Consultar no Moip";
			break;
		}
		return $param;
	}

	private  function _sendStatusMail($order, $tokenpagamento)
	{
		$emailTemplate  = Mage::getModel('core/email_template');
		$emailTemplate->loadDefault('moip_ordem_tpl');
		$emailTemplate->setTemplateSubject('Pedido Cancelado');
		$salesData['email'] = Mage::getStoreConfig('trans_email/ident_general/email');
		$salesData['name'] = Mage::getStoreConfig('trans_email/ident_general/name');
		$emailTemplate->setSenderName($salesData['name']);
		$emailTemplate->setSenderEmail($salesData['email']);
		$emailTemplateVariables['username']  = $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();
		$emailTemplateVariables['order_id'] = $order->getIncrementId();
		$emailTemplateVariables['token'] = $tokenpagamento;
		$emailTemplateVariables['store_name'] = $order->getStoreName();
		$emailTemplateVariables['store_url'] = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
		$emailTemplate->send($order->getCustomerEmail(), $order->getStoreName(), $emailTemplateVariables);
	}

	public function email_erro_pgtoAction() {
		if ($_GET['erro'] != "true"){
			$erro = $_GET['erro'];
			$pedido = $_GET['pedido'];
			$navegador = $_GET['navegador'];
			Mage::log("Cliente do pedido ".$pedido. " - Erro - " .$erro. " navegador ". $navegador, null, 'MOIP_Transparente.log', true);
		}
	}

			
public function post_correio($url, $get) {
				$url = explode('?', $url, 2);
				
				$ch = curl_init($url[0]."?".http_build_query($get));
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				
				return curl_exec($ch);
			}
	public function buscaCepAction() {
		$data = $this->getRequest()->getParams();
		if ($data['meio'] == "buscaend") {
			
			$rua = $data['busca_end'];
			$vSomeSpecialChars = array("á", "á","é","é", "í","í", "ó", "ú", "Á","À","É","È", "Í", "Ì","Ó", "Ú", "ç", "Ç", "ã", "Ã", "õ", "Õ");
			$vReplacementChars = array("a", "a", "e","e", "i","i", "o", "u", "A", "A","E","E", "I", "I","O", "U", "c", "C", "a", "A", "o", "O");
			$rua = str_replace($vSomeSpecialChars, $vReplacementChars, $rua);
			$rua = preg_replace('/[^\p{L}\p{N}]/u', '+', $rua);
			$uf = $data['busca_uf'];
			$url_end = "http://endereco.ecorreios.com.br/getAddress.php?";
			$get = array(
				'cep' =>'',
				'busca_end' =>$rua,
				'busca_uf' =>$uf,
				);
			$config = array('adapter' => 'Zend_Http_Client_Adapter_Socket');
			$resposta = $this->post_correio($url_end, $get);
			
			
			$this->getResponse()->setBody($resposta);
		}

		if ($data['meio'] == "cep") {
			function simple_curl($url, $post=array(), $get=array()) {
				$url = explode('?', $url, 2);
				if (count($url)===2) {
					$temp_get = array();
					parse_str($url[1], $temp_get);
					$get = array_merge($get, $temp_get);
				}
				$ch = curl_init($url[0]."?".http_build_query($get));
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
				curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (iPhone; U; CPU iPhone OS 4_0 like Mac OS X; en-us) AppleWebKit/532.9 (KHTML, like Gecko) Version/4.0.5 Mobile/8A293 Safari/6531.22.7');
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				Mage::log(curl_exec($ch));
				return curl_exec($ch);
			}
			$cep = $data['cep'];
			$cep = substr(preg_replace("/[^0-9]/", "", $cep) . '00000000', 0, 8);
			$url_end = "http://endereco.ecorreios.com.br/getAddress.php?cep={$cep}";
			$config = array('adapter' => 'Zend_Http_Client_Adapter_Socket');
			$client = new Zend_Http_Client($url_end, $config);
			$response = $client->request();
			
			if($response->getBody() != "503"){
			$endereco =  Zend_Json::decode($response->getBody());

			switch ($endereco['uf']) {
				case "AC":
					$endereco['ufid'] = 485;
					break;
				case "AL":
					$endereco['ufid'] = 486;
					break;
				case "AP":
					$endereco['ufid'] = 487;
					break;
				case "AM":
					$endereco['ufid'] = 488;
					break;
				case "BA":
					$endereco['ufid'] = 489;
					break;
				case "CE":
					$endereco['ufid'] = 490;
					break;
				case "DF":
					$endereco['ufid'] = 491;
					break;
				case "ES":
					$endereco['ufid'] = 492;
					break;
				case "GO":
					$endereco['ufid'] = 493;
					break;
				case "MA":
					$endereco['ufid'] = 494;
					break;
				case "MT":
					$endereco['ufid'] = 495;
					break;
				case "MS":
					$endereco['ufid'] = 496;
					break;
				case "MG":
					$endereco['ufid'] = 497;
					break;
				case "PA":
					$endereco['ufid'] = 498;
					break;
				case "PB":
					$endereco['ufid'] = 499;
					break;
				case "PR":
					$endereco['ufid'] = 500;
					break;
				case "PE":
					$endereco['ufid'] = 501;
					break;
				case "PI":
					$endereco['ufid'] = 502;
					break;
				case "RJ":
					$endereco['ufid'] = 503;
					break;
				case "RN":
					$endereco['ufid'] = 504;
					break;
				case "RS":
					$endereco['ufid'] = 505;
					break;
				case "RO":
					$endereco['ufid'] = 506;
					break;
				case "RR":
					$endereco['ufid'] = 507;
					break;
				case "SC":
					$endereco['ufid'] = 508;
					break;
				case "SP":
					$endereco['ufid'] = 509;
					break;
				case "SE":
					$endereco['ufid'] = 510;
					break;
				case "TO":
					$endereco['ufid'] = 511;
					break;
			}
			$this->getResponse()->setBody(Zend_Json::encode($endereco));
			}
		}
	}
}