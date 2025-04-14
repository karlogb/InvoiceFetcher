<?php
/*
© 2024 Karol Bolgár. All rights reserved.
*/
?>


<?php

include_once __DIR__ . '/vendor/autoload.php';

$env_path  = __DIR__ . '/.env';
$env = parse_ini_file(__DIR__ . '/.env');

function writeLog($message) {
    // Log file
    $logFile = __DIR__ . '/invoice_log.txt';

    // Format the current date and time
    $dateTime = date('[d.m.Y][H:i:s]');

    // Creating formatted message
    $logMessage = $dateTime . ' ' . $message . PHP_EOL;

    // Writing a message to a file
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

writeLog('Running script');

$api = new \SuperFaktura\ApiClient\ApiClient(
    new \SuperFaktura\ApiClient\Authorization\EnvFileProvider($env_path),
	constant('\SuperFaktura\ApiClient\MarketUri::' . $env["MARKET"]),
);

writeLog('SuperFaktura API connected');

// Getting the ID from the request body
$request_body = file_get_contents('php://input');
$data = json_decode($request_body, true);

if (!is_array($data)) {
    echo 'Invalid input';
    writeLog('Invalid input');
    exit;
}

if (count($data) == 0) {
    echo 'No data specified';
    writeLog('No data specified');
    exit;
}

$data_invoices = [];

// Creating objects and storing them in an array by ID
foreach ($data["data"] as $item) {
    if (isset($item['id'], $item['date'], $item['currency'])) {
        $id = $item['id'];
        $data_invoices[$id] = (object)[
            'idObjednavky' => $id,
            'date' => $item['date'],
            'currency' => $item['currency']
        ];
    }
}

// Functions to retrieve invoice data by order ID
function getInvoice($id) {
    global $data_invoices;

    if (isset($data_invoices[$id])) {
        return $data_invoices[$id];
    }

    return null;
}

$ids = array_keys($data_invoices);
writeLog('Retrieve invoice ID by order number from API [' . implode(', ', $ids) . ']');

$id_faktur = [];

foreach ($ids as $idObjednavky) {
	writeLog('Invoice search by order ID: ' . $idObjednavky);

	$result = $api->invoices->getAll(
		new SuperFaktura\ApiClient\UseCase\Invoice\InvoicesQuery(
			order_number: $idObjednavky,
			type: SuperFaktura\ApiClient\Contract\Invoice\InvoiceType::PROFORMA
		)
	);

	if($result->status_code == 200) {
		if($result->data['itemCount'] > 0) {
			
			foreach ($result->data['items'] as $invoiceData) {
				$invoice_id = $invoiceData['Invoice']['id'];
				
				writeLog('Invoice found with order number ' . $idObjednavky . ' Invoice ID - ' . $invoice_id);

				$id_faktur[] = $invoice_id;

				$data_invoices[$idObjednavky]->idFaktury = $invoice_id;
				$data_invoices[$idObjednavky]->clientEmail = $invoiceData['ClientData']['email'];
			}
		}
		else {
			writeLog('Invoice with order number ' . $idObjednavky . ' not found');
		}
	}
	else {
		writeLog('An error occurred during the processing of the application');
		writeLog(json_encode($result));
	}
}

writeLog('Invoice retrieval by ID [' . implode(', ', $id_faktur) . ']');

// Retrieve invoices by ID
$response = $api->invoices->getByIds($id_faktur);

if($response->status_code == 200) {
	writeLog('Loading invoices, start filtering');
}
else {
	writeLog('An error occurred during the processing of the application');
	writeLog(json_encode($response));
	exit;
}

$faktury = [];

foreach ($response->data as $invoiceData) {
	$invoice = $invoiceData['Invoice'];
	
	writeLog('Invoice control - ID: ' . $invoice['id']);

	// Check profoma invoices
	if(isset($invoice['type']) && $invoice['type'] === "proforma") {		
		// Shouldn't there already be a crisp invoice for it
		if(!isset($invoice['parent_id'])) {
			writeLog('I accept profoma invoice for processing - ID: ' . $invoice['id']);
			$faktura = getInvoice($invoice['order_no']);
			$faktura->invoice = $invoice;

			$faktury[] = $faktura;
		}
		else {
			$regular_id_exists = $invoice['parent_id'];
			writeLog('The invoice has been filtered, a invoice has already been issued for the profoma invoice (profoma -> invoice): ' . $invoice['id'] . ' -> ' . $regular_id_exists);
		}
	}
	else {
		writeLog('Faktúra vyfiltrovaná, nie je zálohová - ID: ' . $invoice['id']);
	}
}

if (count($faktury) == 0) {
    echo 'No invoices to process';
	writeLog('No invoices to process');
    exit;
}

// Creating invoices from profoma
foreach ($faktury as $faktura) {
	$id_faktury = $faktura->idFaktury;

	writeLog('I create a invoice from an advance invoice - ID: ' . $faktura->idFaktury);

	$response_create_regular_from_proforma = $api->invoices->createRegularFromProforma($id_faktury);
	
	if($response_create_regular_from_proforma->status_code == 200 && $response_create_regular_from_proforma->data['error'] == 0) {
		$regularInvoice = $response_create_regular_from_proforma->data['data']['Invoice'];
		$regularInvoiceSummary = $response_create_regular_from_proforma->data['data']['Summary'];
		$regular_id = $regularInvoice['id'];
		$amount_to_pay = $regularInvoiceSummary['invoice_total'];

		writeLog('Created invoice (profoma -> invoice): ' . $id_faktury . ' -> ' . $regular_id);
		
		writeLog('Sending payment of the invoice ' . $amount_to_pay . ' ' . $faktura->currency);
		$response_pay_proforma = $api->invoices->payments->create(
			id: $id_faktury,
			payment: new SuperFaktura\ApiClient\UseCase\Invoice\Payment\Payment(
				amount: $amount_to_pay,
				currency: SuperFaktura\ApiClient\UseCase\Money\Currency::from($faktura->currency),
				payment_type: SuperFaktura\ApiClient\Contract\PaymentType::COD,
				payment_date: DateTimeImmutable::createFromFormat('Y-m-d', $faktura->date)
			)
		);

		writeLog('Editing the dates for a crisp invoice - ID: ' . $regular_id);

		$response = $api->invoices->update(
			id: $regular_id,
			invoice: [
				"delivery" => $faktura->date,
				"created" => $faktura->date
			]
		);

		if($response_pay_proforma->status_code == 200 && $response_pay_proforma->data['error'] == 0) {
			writeLog('Invoice paid - Payment ID: ' . $response_pay_proforma->data['payment_id']);
			
			if (!isset($faktura->clientEmail) || empty($faktura->clientEmail)) {
				writeLog('Sending invoice to email skipped, email not entered');
			}
			else {
				writeLog('Sending invoice to email ' . $faktura->clientEmail);
	
				// Default slovenčina
				$invoiceLang = 'slo'; 
				$subject = 'Faktúra ' . $regular_id;
	
				// If the payment is in CZK, set the language to Czech
				if($faktura->currency == 'CZK') {
					$invoiceLang = 'cze';
					$subject = 'Faktura ' . $regular_id;
				}
	
				$api->invoices->sendViaEmail(
					$regular_id,
					new \SuperFaktura\ApiClient\UseCase\Invoice\Email(
						email: $faktura->clientEmail,
						pdf_language: \SuperFaktura\ApiClient\Contract\Language::from($invoiceLang),
						subject: $subject,
						message: '' // auto generated
					)
				);
			}
		}
		else {
			writeLog('An error occurred while processing the request');
			writeLog(json_encode($response_pay_proforma));
		}
	}
	else {
		writeLog('An error occurred while processing the request');
		writeLog(json_encode($response_create_regular_from_proforma));
	}
}

echo "OK";

?>
