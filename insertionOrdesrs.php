<?php

namespace IO\IndexBundle\Controller;

use Core\BusinessDealNumbersExcelExport;
use PHPExcel_Style_Alignment;
use PHPExcel_Style_Border;
use PHPExcel_Style_Fill;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TCPDF;
use Tracking\IndexBundle\Controller\ClientsController;

if (isset($_SERVER['SERVER_ADDR']) && ip2long($_SERVER['SERVER_ADDR']) != ip2long('127.0.0.1')) {
    if (file_exists('/var/www/acq.gameloft.org/documents/adserver/vendor/tecnick.com/tcpdf/tcpdf.php')) {
        require_once '/var/www/acq.gameloft.org/documents/adserver/vendor/tecnick.com/tcpdf/tcpdf.php';
    }
} else {
    if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/../vendor/tecnick.com/tcpdf/tcpdf.php')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/../vendor/tecnick.com/tcpdf/tcpdf.php';
    }
}

class InsertionOrdersController extends Controller
{
    const DEFAULT_ITEMS_PER_PAGE = 10;
    private $filters = array();
    private $filtersToApply = array();

    public function __construct()
    {
        session_start();

        global $kernel;
        $kernel->getContainer()->get('UsersModel')->refreshUserSession();

        if (!isset($_SESSION['user'])) {
            $data = array('error' => true, 'error_code' => 'unlogged', 'message' => 'It appears you are not logged. Please refresh to login and continue using the app.');
            echo json_encode($data);
            exit();
        }
    }

    public function update_dio_ad_formatAction() {
        $dioID = $_POST['insertion_order_id'];

        $data = [
            'id' => $_POST['id'],
            'cmp_end_date' => $_POST['cmp_end_date'],
            'status' => $_POST['status'],
            'freetext' => $_POST['freetext']
        ];

        $tblOldState = $this->get('HistoryInsertionOrdersModel')->getCurrentState($dioID);
        $success = $this->get('InsertionOrdersModel')->updateDIOAdFormat($data);
        $this->get('HistoryInsertionOrdersModel')->change($dioID, $tblOldState);

        return new JsonResponse(array('success' => $success));
    }

    public function get_listAction($insertionOrder_id = null, $source = null)
    {

        global $kernel;

        $request = Request::createFromGlobals();

        $postData = array();
        $postData['startingRecord'] = $request->request->get('startingRecord');
        $postData['sortField'] = $request->request->get('sortField');
        $postData['sortOrder'] = $request->request->get('sortOrder');
        $postData['itemsPerPage'] = $request->request->get('itemsPerPage');
        $postData['searchTerm'] = $request->request->get('searchTerm');
        $postData['manage_io'] = $request->request->get('manage_io');

        $postData['itemsPerPage'] = (isset($postData['itemsPerPage'])) ? (int)$postData['itemsPerPage'] : self::DEFAULT_ITEMS_PER_PAGE;
        $postData['startingRecord'] = (isset($postData['startingRecord']) and (int)$postData['startingRecord'] >= 0) ? (int)$postData['startingRecord'] : 0;

        if (!empty($_POST['filters']) && !isset($_POST['filters']['id'])) {
            $this->filters = $_POST['filters'];
        } else {
            $this->filters = null;
        }

        $filteredResult = array();
        $this->filtersToApply = array();

        if (!empty($this->filters)) {
            foreach ($this->filters as $filter => $value) {
                if (($value > 0) || ($value != '0')) {
                    if ($filter == 'mediaGroups') {
                        $filter = 'mediaGroupsAgency';
                        $value = $value . ',';
                    }
                    if ($filter == 'mediaAgencies') {
                        $filter = 'mediaGroupsAgency';
                        $value = ',' . $value;
                    }
                    if ($filter == 'signature_date') {
                        $filter = 'io_signature_date';
                        switch ($value) {
                            case '0' :
                                break;
                            case '1' :
                                $value = date('Y-m-d 00:00:00', strtotime("-1 months"));
                                break;
                            case '2' :
                                $value = date('Y-m-d 00:00:00', strtotime("-3 months"));
                                break;
                            case '3' :
                                $value = date('Y-m-d 00:00:00', strtotime("-6 months"));
                                break;
                            case '4' :
                                $value = date('Y-01-01 00:00:00');
                                break;
                        }
                    }
                    $this->filtersToApply[$filter] = $value;
                }
            }
        }

        $insertionOrderData = $kernel->getContainer()->get('InsertionOrdersModel')->get_list($insertionOrder_id, $postData, $this->filtersToApply);

        $totalItems = $insertionOrderData['totalItemsCount'];
        $insertionOrderDataFormatted = array();

        foreach ($insertionOrderData['items'] as $key => $value) {
            array_push($insertionOrderDataFormatted, $value);
        }

        $postSearchTerm = trim((isset($_POST['searchTerm']) ? $_POST['searchTerm'] : ''));
        preg_match('/^[0-9]+(,[0-9]+)+/', $postSearchTerm, $matches);
        $searchTerm = ($postSearchTerm !== "" && isset($matches[0]) && $matches[0] == $postSearchTerm) ? explode(',', $postSearchTerm) : $postSearchTerm;


        foreach ($insertionOrderDataFormatted as $key => $value) {
            $toDelete = false;

            $searchKeyToCheck = array('advertised_brand', 'advertised_company', 'campaign_name', 'user_name', 'company_purchase_order', 'client_name', 'deal_number');
            if (!empty($searchTerm)) {
                if ((is_array($searchTerm) && !in_array($value['deal_number'], $searchTerm)) ||
                    (!is_array($searchTerm) &&
                        strpos(strtolower($value['advertised_brand']), strtolower($searchTerm)) === false &&
                        strpos(strtolower($value['advertised_company']), strtolower($searchTerm)) === false &&
                        strpos(strtolower($value['campaign_name']), strtolower($searchTerm)) === false &&
                        strpos(strtolower($value['user_name']), strtolower($searchTerm)) === false &&
                        strpos(strtolower($value['company_purchase_order']), strtolower($searchTerm)) === false &&
                        strpos(strtolower($value['client_name']), strtolower($searchTerm)) === false &&
                        strpos(strtolower($value['invoicingEntityName']), strtolower($searchTerm)) === false &&
                        strpos(strtolower($value['deal_number']), strtolower($searchTerm)) === false)
                ) {
                    $toDelete = true;
                }
            }

            if ($toDelete) {
                unset($insertionOrderDataFormatted[$key]);
                $totalItems--;
            }
        }

        if ($source == 'api_edit') {

            $dio_files = [];

            $dio_files_sum = [];

            $billingContact = $kernel->getContainer()->get('ClientsModel')->get_billing_contacts($insertionOrderDataFormatted[0]['client_id'], $insertionOrderDataFormatted[0]['billing_contact_id']);

            $billingDetails = $kernel->getContainer()->get('ClientsModel')->get_details($insertionOrderDataFormatted[0]['client_id']);

            if (isset($billingContact[0])) {
                unset($billingContact[0]['id'], $billingContact[0]['creation'], $billingContact[0]['modification']);
                $insertionOrderDataFormatted[0] = array_merge($insertionOrderDataFormatted[0], $billingContact[0]);
            }
            //print_r($billingDetails);die;
            if (isset($billingDetails)) {
                $billingDetails['country_name'] = $this->numeric_check('country', $billingDetails['country']);
                $insertionOrderDataFormatted[0]['client_details'] = $billingDetails;
            }

            foreach ($insertionOrderDataFormatted[0]['signedDio'] as $k => $v) {
                if (strpos($v['filename'], 'summarized') > 0) {
                    $dio_files_sum[] = array(
                        "id" => $v['id'],
                        "filename" => $v['filename'],
                        //"path" => $_SERVER['DOCUMENT_ROOT'],
                        "url" => "https://acq.gameloft.com/api/salesforce/insertion-orders/download_dio/" . $v['id']
                    );
                } else {
                    $dio_files[] = array(
                        "id" => $v['id'],
                        "filename" => $v['filename'],
                        //"path" => $_SERVER['DOCUMENT_ROOT'],
                        "url" => "https://acq.gameloft.com/api/salesforce/insertion-orders/download_dio/" . $v['id']
                    );
                }
            };

            unset($insertionOrderDataFormatted[0]['signedDio']);

            $insertionOrderDataFormatted[0]['files'] = $dio_files;

            $insertionOrderDataFormatted[0]['summarized'] = $dio_files_sum;

            //$this->generate_dio_pdf($insertionOrderDataFormatted);die;

            //return new JsonResponse(array($insertionOrderDataFormatted[0]));
            return $insertionOrderDataFormatted;
        }

        if ($source == 'api_pdf_save') {
            unset($insertionOrderDataFormatted[0]['signedDio']);
            return $insertionOrderDataFormatted;
        }

        //$this->generate_dio_pdf($insertionOrderDataFormatted);die;

        return new JsonResponse(array(
            'items' => $insertionOrderDataFormatted,
            'totalItems' => $totalItems
        ));
    }

    /**
     * Save new RETARGETING LABEL [/web/tracking/retargeting-labels/add]
     *
     * @access public
     * @param  NULL
     *
     * @return JSON   Return sucess or error details
     */
    public function put_addAction()
    {

        global $kernel;

        try {
            $request = Request::createFromGlobals();
            $postData['insertionOrderId'] = $request->request->get('insertionOrderId');
            $postData['advertising_brand_id'] = $request->request->get('advertising_brand_id');
            $postData['user_id'] = $request->request->get('user_id');
            $postData['insertion_orders_contacts'] = $request->request->get('insertion_orders_contacts');
            $postData['io_campaign_managers'] = $request->request->get('io_campaign_managers');
            $postData['invoicing_entity_id'] = $request->request->get('invoicing_entity_id');
            $postData['save_type'] = $request->request->get('save_type');
            $postData['io_signature_date'] = $request->request->get('io_signature_date');
            $postData['dio_start_date'] = $request->request->get('dio_start_date');
            $postData['dio_end_date'] = $request->request->get('dio_end_date');
            $postData['advertised_company_id'] = $request->request->get('advertised_company_id');
            $postData['campaign_name'] = $request->request->get('campaign_name');
            $postData['company_purchase_order'] = $request->request->get('company_purchase_order');
            $postData['currency'] = $request->request->get('currency');
            $postData['amount'] = $request->request->get('amount');
            $postData['deal_number'] = $request->request->get('deal_number');
            $postData['deal_number_label'] = $request->request->get('deal_number_label');
            $postData['deal_number_extend'] = $request->request->get('deal_number_extend');
            $postData['client_id'] = $request->request->get('client_id');
            $postData['adv_prod_serv'] = $request->request->get('adv_prod_serv');
            $postData['games'] = $request->request->get('games');
            $postData['total_cost'] = $request->request->get('total_cost');
            $postData['total_net_cost'] = $request->request->get('total_net_cost');
            $postData['client_rebate'] = $request->request->get('client_rebate');
            $postData['authorized_sales_name'] = $request->request->get('authorized_sales_name');
            $postData['authorized_sales_title'] = $request->request->get('authorized_sales_title');
            $postData['authorized_client_name'] = $request->request->get('authorized_client_name');
            $postData['authorized_client_title'] = $request->request->get('authorized_client_title');
            $postData['creativeTypes'] = $request->request->get('creativeTypes');
            $postData['additional_terms'] = $request->request->get('additional_terms');
            $postData['mediaGroupsAgency'] = $request->request->get('mediaGroupAgency');
            $postData['related_party'] = $request->request->get('related_party');
            $postData['invoice_term'] = $request->request->get('invoice_term');
            $postData['modification'] = $request->request->get('modification');
            $postData['dio_type'] = $request->request->get('dio_type');

            if ($postData['insertionOrderId'] > 0 && $postData['modification'] != 0) {
                $modification = $kernel->getContainer()->get('InsertionOrdersModel')->get_last_modification($postData['insertionOrderId']);
                if ($postData['modification'] != $modification) {
                    return new Response(json_encode(
                        array(
                            'success' => false,
                            'type' => 'unreliable',
                            'error' => 'The data you are trying to save is not the most recent one. Please refresh the page and retry to save your DIO!'
                        )
                    ));
                }
            }

            if (!isset($postData['age_targeting_from'])) $postData['age_targeting_from'] = $request->request->get('age_targeting_from');
            if (!isset($postData['age_targeting_to'])) $postData['age_targeting_to'] = $request->request->get('age_targeting_to');

            if (!isset($postData['deal_number_extend'])) $postData['deal_number_extend'] = 1;
            if (!isset($_POST['business_contact_id'])) $_POST['business_contact_id'] = 0;

            $postData['deal_number_id'] = $kernel->getContainer()->get('BusinessDealNumbersModel')->getIdByDealNumber($postData['deal_number'], $postData['deal_number_extend']); //
            $postData['billing_contact_id'] = $_POST['business_contact_id'];
            $postData['dio_status'] = $request->request->get('dio_status');
            $postData['notification'] = $request->request->get('notification');
            $postData['advertised_brands_industry_sector_category_id'] = $request->request->get('advertised_brands_industry_sector_category_id');
            $postData['advertised_brands_industry_sector_id'] = $request->request->get('advertised_brands_industry_sector_id');
            $postData['network'] = $request->request->get('network');
            $postData['currencyName'] = $request->request->get('currencyName');

            $postData['email_notification'] = $request->request->get('email_notification');

            $postData['email_notification']['io_campaign_managers'] = $postData['io_campaign_managers'];

            if ($postData['currencyName'] == '') {
                $postData['currencyName'] = $kernel->getContainer()->get('CurrenciesModel')->get_details($postData['currency']);
                $postData['currencyName'] = $postData['currencyName']['short_name'];
            }

            $tempCurrencyNameArray = $kernel->getContainer()->get('CurrenciesModel')->get_details($postData['currency']);
            $tempCurrencyName = $tempCurrencyNameArray['short_name'];

            $postData['eurExchangeRate'] = 1;

            $postData['notifyHappn'] = 0;

            if ($postData['currencyName'] != 'EUR' || $postData['currency'] != 4) {
                $postData['eurExchangeRate'] = $kernel->getContainer()->get('InsertionOrdersModel')->getExchangeRates($tempCurrencyName, 'EUR', $postData['io_signature_date'], 1);
            }

            $postData['total_net_cost'] = str_replace(",", "", $postData['total_net_cost']);

            $postData['eurExchange'] = 'EUR ' . floatval($postData['total_net_cost']) * floatval($postData['eurExchangeRate']);

            if (strpos($postData['eurExchange'], 'EUR') !== false) {
                $postData['eurExchange'] = explode('EUR ', $postData['eurExchange'])[1];
            }

            $postData['eurExchange'] = str_replace(",", "", $postData['eurExchange']);

            $postData['eurExchange'] = number_format($postData['eurExchange'], 2, '.', '');

            $postData['email_notification']['eurExchange'] = $postData['eurExchange'];

            if ($postData['dio_status'] == null) $postData['dio_status'] = 5;

            if (empty(explode('-', $postData['deal_number_label'])[1])) {
                $postData['deal_number_label'] =
                    explode('-', $postData['io_signature_date'])[0] . '-' .
                    $this->get('InvoicingEntitiesModel')->get_list($postData['invoicing_entity_id'], null)['items'][0]['invoicing_entity_io'] . '-' .
                    $postData['deal_number'];
            }

            $dioEffectiveDates = [];

            $adOpsNotification = [];

            $adOpsNotification['email_notification'] = $postData['email_notification'];

            $adOpsNotification['email_notification']['ad_ops_notification'] = 0;

            if (isset($postData['creativeTypes']) && !empty($postData['creativeTypes'])) {
                foreach ($postData['creativeTypes'] as $key => $value) {

                    if (!isset($value['cmp_end_date'])) $value['cmp_end_date'] = '0000-00-00';

                    $dioEffectiveDates[] = $value['cmp_end_date'];

                    if (isset($postData['creativeTypes'][$key]['country_id']) && !empty($postData['creativeTypes'][$key]['country_id'])) {
                        if (is_array($value['country_id']) && count($value['country_id']) > 0) {
                            $postData['creativeTypes'][$key]['country_id'] = implode("-", $value['country_id']);
                        } else if (strlen($value['country_id']) > 0) {
                            $postData['creativeTypes'][$key]['country_id'] = $value['country_id'];
                        } else {
                            $postData['creativeTypes'][$key]['country_id'] = 0;
                        }
                    }
                    if (isset($postData['creativeTypes'][$key]['platform_id']) && !empty($postData['creativeTypes'][$key]['platform_id'])) {
                        if (is_array($value['platform_id']) && count($value['platform_id']) > 0) {
                            $postData['creativeTypes'][$key]['platform_id'] = implode("-", $value['platform_id']);
                        } else if (strlen($value['platform_id']) > 0) {
                            $postData['creativeTypes'][$key]['platform_id'] = $value['platform_id'];
                        } else {
                            $postData['creativeTypes'][$key]['platform_id'] = 9;
                        }
                    }
                    if (isset($postData['creativeTypes'][$key]['games_id']) && !empty($postData['creativeTypes'][$key]['games_id']) && is_array($postData['creativeTypes'][$key]['games_id'])) {
                        if (is_array($value['games_id']) && count($value['games_id']) > 0) {
                            $postData['creativeTypes'][$key]['games_id'] = implode(",", $value['games_id']);
                        } else if (strlen($value['games_id']) > 0) {
                            $postData['creativeTypes'][$key]['games_id'] = $value['games_id'];
                        } else {
                            $postData['creativeTypes'][$key]['games_id'] = 0;
                        }
                    }

                    $net_unit_price = str_replace(",", "", $value['net_unit_price']);
                    $net_cost = str_replace(",", "", $value['net_cost']);

                    $net_unit_price = number_format($net_unit_price, 4, '.', '');
                    $net_cost = number_format($net_cost, 2, '.', '');

                    $postData['creativeTypes'][$key]['net_unit_price_euro'] = $net_unit_price * $postData['eurExchangeRate'];
                    $postData['creativeTypes'][$key]['net_cost_euro'] = $net_cost * $postData['eurExchangeRate'];

                    if ($postData['save_type'] == 2) {

                        if ($postData['notification'] != 1) {

                            $sendNotification = 0;

                            if (isset($value['ad_format_id']) && $value['ad_format_id'] != '') {

                                $currentCreativeInformation = $kernel->getContainer()->get('InsertionOrdersModel')->getDioCreativeTypeInfo($value['ad_format_id']);

                                foreach ($currentCreativeInformation as $k => $ad_format_data) {

                                    $impressionsDB = str_replace(",", "", $ad_format_data['impressions_count']);
                                    $impressions = str_replace(",", "", $value['impressions_count']);
                                    $net_unit_price_DB = str_replace(",", "", $ad_format_data['net_unit_price']);

                                    $unit_price_DB = str_replace(",", "", $ad_format_data['price']);

                                    $net_unit_price = str_replace(",", "", $value['net_unit_price']);

                                    $net_unit_price = number_format($net_unit_price, 4, '.', '');

                                    $unit_price = str_replace(",", "", $value['price']);
                                    $unit_price = number_format($unit_price, 4, '.', '');

                                    if (date($value['start_date']) != date($ad_format_data['start_date']) ||
                                        date($value['end_date']) != date($ad_format_data['end_date']) ||
                                        $impressionsDB != $impressions ||
                                        $net_unit_price != $net_unit_price_DB ||
                                        $unit_price != $unit_price_DB ||
                                        $value['creative_type_id'] != $ad_format_data['creative_type_id'] ||
                                        $value['pricing_model_id'] != $ad_format_data['pricing_model_id']) {

                                        $adOpsNotification['email_notification']['ad_format_new'][$value['ad_format_id']] = $value;

                                        $adOpsNotification['email_notification']['ad_format_new'][$value['ad_format_id']]['network_name'] = $value['network_name'];

                                        $adOpsNotification['email_notification']['ad_format_new'][$value['ad_format_id']]['old_pricing_model'] = $ad_format_data['pricing_model_id'];

                                        $adOpsNotification['email_notification']['ad_format_new'][$value['ad_format_id']]['old_creative_type_id'] = $ad_format_data['creative_type_id'];

                                        $adOpsNotification['email_notification']['ad_format_new'][$value['ad_format_id']]['old_impressions'] = $impressionsDB;
                                        $adOpsNotification['email_notification']['ad_format_new'][$value['ad_format_id']]['old_unit_price'] = $unit_price_DB;
                                        $adOpsNotification['email_notification']['ad_format_new'][$value['ad_format_id']]['is_free'] = $value['is_free'];

                                        $adOpsNotification['email_notification']['ad_ops_notification'] = 1;

                                        $sendNotification = 1;

                                        if ($value['network'] == 2) {
                                            $postData['notifyHappn'] = 1;
                                            break;
                                        }
                                    }

                                    if($ad_format_data['is_free'] != $value['is_free']) {
                                        unset($adOpsNotification['email_notification']['ad_format_new'][$value['ad_format_id']]);
                                    }

                                }

                                $adOpsNotification['email_notification']['insertion_orders_contacts'] = $postData['insertion_orders_contacts'];

                                if ($sendNotification == 1) {
                                    if ($value['network'] == 2) {
                                        $this->generate_Happn_notification($postData);
                                    }
                                }
                            }
                        } else {
                            if ($value['network'] == 2) {
                                $postData['notifyHappn'] = 1;
                            }
                        }
                    }
                }
            }

            if ($adOpsNotification['email_notification']['ad_ops_notification'] == 1) {
                $adOpsNotification['email_notification']['io_campaign_managers'] = [];
                $adOpsNotification['email_notification']['insertion_orders_contacts'] = [];
                foreach($postData['email_notification']['io_campaign_managers'] as $adops) {
                    $adOpsNotification['email_notification']['io_campaign_managers'][] = $kernel->getContainer()->get('UsersModel')->get_user_details($adops)->email;
                }
                foreach($postData['insertion_orders_contacts'] as $adops) {
                    $adOpsNotification['email_notification']['insertion_orders_contacts'][] = $kernel->getContainer()->get('UsersModel')->get_user_details($adops)->email;
                }
                $this->generate_notification($adOpsNotification['email_notification'], 0, 'changes');
            }

            $billingContactData = $kernel->getContainer()->get('ClientsModel')->get_billing_contacts(null, $_POST['business_contact_id']);

            if ((count($billingContactData) > 0 && $billingContactData[0]['business_contact_name'] != $_POST['business_contact_name'])) {
                $_POST['business_contact_id'] = 0;
            }

            if ((isset($_POST['client_id']) && !empty($_POST['client_id']))) {
                //$clientData = ClientsController::put_editAction(1);
                $clientController = new ClientsController;
                $clientData = $clientController->put_editAction(1);

                $id = $clientData['clientContactId']->getContent();
                $id = json_decode($id, true);
                $postData['billing_contact_id'] = $id;
            }

            $blnIsInsert = false;
            $tblOldState = array();
            if ($postData['insertionOrderId'] == null) {
                $insertionOrderDataId = $kernel->getContainer()->get('InsertionOrdersModel')->addInsertionOrder($postData);

                if (is_array($insertionOrderDataId) || strpos($insertionOrderDataId, 'Dberror') !== false) {
                    return new Response(json_encode(array(
                        'success' => false,
                        'error' => 'Please select another business deal number. The business deal number has already been created'
                            . ' by someone else while attempting to save your session.')));

                } else {
                    $kernel->getContainer()->get('HistoryInsertionOrdersModel')->add($insertionOrderDataId);
                }

            } else {

                $tblOldState = $kernel->getContainer()->get('HistoryInsertionOrdersModel')->getCurrentState($postData['insertionOrderId']);
                $kernel->getContainer()->get('InsertionOrdersModel')->updateInsertionOrder($postData);
                $kernel->getContainer()->get('HistoryInsertionOrdersModel')->change($postData['insertionOrderId'], $tblOldState);

            }

//            if ($postData['notification'] == 1) {
//                $postData['email_notification'] = $request->request->get('email_notification');
//                //$dioCurrency = $this->get('CurrenciesModel')->get_details($postData['currency']);
//                //$postData['email_notification']['dioCurrency'] = $dioCurrency['short_name'];
//                $postData['email_notification']['eurExchange'] = $postData['eurExchange'];
//                if ($postData['insertionOrderId'] == null && isset($insertionOrderDataId)) {
//                    //$postData['email_notification']['link'] = str_replace("undefined", $insertionOrderDataId, $postData['email_notification']['link']);
//                    $postData['email_notification']['link'] = "https://acq.gameloft.org/adserver/web/insertion-orders/get_edit/$insertionOrderDataId";
//                    $postData['email_notification']['total_net_cost'] = str_replace("undefined", $postData['email_notification']['currency_name'], $postData['email_notification']['total_net_cost']);
//                    $postData['email_notification']['deal_number_extend'] = isset($postData['deal_number_extend']) ? $postData['deal_number_extend'] : 1;
//                }
//
//                $this->generate_notification($postData['email_notification']);
//
//                if (isset($postData['notifyHappn']) && $postData['notifyHappn'] == 1) {
//                    $this->generate_Happn_notification($postData);
//                }
//            }

            if (isset($insertionOrderDataId)) {

                if (is_array($insertionOrderDataId)) {
                    if (isset($insertionOrderDataId['Dberror'])) {
                        return new Response(json_encode(array('success' => false, 'error' => 'Please select another business deal number. The business deal number has already been created'
                            . 'by someone else while attempting to save your session.')));
                    }
                }

                $salesForceCurl = $this->curl_sales_force($insertionOrderDataId);

                if (!empty($salesForceCurl)) {
                    return new Response(json_encode(array('success' => true, 'id' => $insertionOrderDataId)));
                } else {
                    return new Response(json_encode(array('success' => true, 'id' => $insertionOrderDataId, 'message' => 'Data saved to GLADS but could not contact SalesForce for update!')));
                }

            } else {

                $salesForceCurl = $this->curl_sales_force($postData['insertionOrderId']);

                if (!empty($salesForceCurl)) {
                    return new Response(json_encode(array('success' => true)));
                } else {
                    return new Response(json_encode(array('success' => true, 'message' => 'Data saved to GLADS but could not contact SalesForce for update!')));
                }
            }

        } catch (Exception $e) {

            return new Response(json_encode(array('success' => false, 'error' => $e->getMessage())));

        }
    }

    private function curl_sales_force($id)
    {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://gameloft.secure.force.com/api/services/apexrest/DIO/update?token=d4FJe17NXam9UfBLRCZ6",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => "[$id]",
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        return new response(json_encode($response));

    }

    public function addHistory()
    {
        if ($blnIsInsert)
            $this->get('HistoryCampaignsModel')->add($id);
        else
            $this->get('HistoryCampaignsModel')->change($id, $tblOldState);
    }

    public function deleteAction($insertionOrder_id)
    {
        $this->get('HistoryInsertionOrdersModel')->remove($insertionOrder_id);
        return new Response(json_encode(array('result' => $this->get('InsertionOrdersModel')->deleteInsertionOrder($insertionOrder_id))));
    }

    public function getInsertionOrderData($insertionOrder_id = null)
    {

        $insertionOrderDataFormatted = array();

        foreach ($insertionOrderData['items'] as $key => $value) {
            array_push($insertionOrderDataFormatted, $value);
        }

        $advBrandData = $this->get('AdvertisedBrandsModel')->get_name_by_id($insertionOrderDataFormatted[0]['advertising_brand_id']);
        $insertionOrderDataFormatted[0]['advertising_brand_name'] = $advBrandData;

        //$clientData = $this->get('ClientsModel')->get_name_by_id($insertionOrderDataFormatted[0]['client_id']);
        //$insertionOrderDataFormatted[0]['client_name'] = $clientData;

        $clientData = $this->get('ClientsModel')->get_details($insertionOrderDataFormatted[0]['client_id']);
        $insertionOrderDataFormatted[0]['client_name'] = $clientData['items'][0];

        $billingPartnerData = $this->get('BillingPartnersModel')->get_list($insertionOrderDataFormatted[0]['billing_partner_id'], array());
        $insertionOrderDataFormatted[0]['billing_partner_data'] = $billingPartnerData['items'][0];

        $userData = $this->get('UsersModel')->get_user_details($insertionOrderDataFormatted[0]['user_id']);
        $insertionOrderDataFormatted[0]['user_data'] = $userData;
        $invoicingEntityData = $this->get('InvoicingEntitiesModel')->get_list($insertionOrderDataFormatted[0]['invoicing_entity_id'], array());
        $insertionOrderDataFormatted[0]['invoicing_entity_data'] = $invoicingEntityData['items'][0];

        foreach ($insertionOrderDataFormatted[0]['games'] as $key => $value) {
            $gameData = $this->get('GamesModel')->get_game_details_by_id($value);
            $insertionOrderDataFormatted[0]['games'][$key] = $gameData->name;
        }

        foreach ($insertionOrderDataFormatted[0]['creativeTypes'] as $key => $value) {
            $countryData = $this->get('CountriesModel')->get_country($value['country_id']);
            $platformData = $this->get('PlatformsModel')->get_platform($value['platform_id']);
            $creativeTypeData = $this->get('CreativesModel')->get_creative_type($value['creative_type_id']);

            $insertionOrderDataFormatted[0]['creativeTypes'][$key]['countryName'] = $countryData[0]['name'];
            $insertionOrderDataFormatted[0]['creativeTypes'][$key]['platformName'] = $platformData['name'];
            $insertionOrderDataFormatted[0]['creativeTypes'][$key]['creativeTypeName'] = $creativeTypeData[0]['label'];
        }
    }

    public function get_pdfAction($insertionOrder_id = null)
    {

        $insertionOrderData = $this->get('InsertionOrdersModel')->get_list($insertionOrder_id, array());
        $insertionOrderDataFormatted = array();

        foreach ($insertionOrderData['items'] as $key => $value) {
            array_push($insertionOrderDataFormatted, $value);
        }

        $advBrandData = $this->get('AdvertisedBrandsModel')->get_name_by_id($insertionOrderDataFormatted[0]['advertising_brand_id']);
        $insertionOrderDataFormatted[0]['advertising_brand_name'] = $advBrandData;

        $clientData = $this->get('ClientsModel')->get_name_by_id($insertionOrderDataFormatted[0]['client_id']);
        $insertionOrderDataFormatted[0]['client_name'] = $clientData;

        $billingPartnerData = $this->get('BillingPartnersModel')->get_list($insertionOrderDataFormatted[0]['billing_partner_id'], array());
        $insertionOrderDataFormatted[0]['billing_partner_data'] = $billingPartnerData['items'][0];

        $userData = $this->get('UsersModel')->get_user_details($insertionOrderDataFormatted[0]['user_id']);
        $insertionOrderDataFormatted[0]['user_data'] = $userData;
        $invoicingEntityData = $this->get('InvoicingEntitiesModel')->get_list($insertionOrderDataFormatted[0]['invoicing_entity_id'], array());
        $insertionOrderDataFormatted[0]['invoicing_entity_data'] = $invoicingEntityData['items'][0];

        foreach ($insertionOrderDataFormatted[0]['games'] as $key => $value) {
            $gameData = $this->get('GamesModel')->get_game_details_by_id($value);
            $insertionOrderDataFormatted[0]['games'][$key] = $gameData->name;
        }

        foreach ($insertionOrderDataFormatted[0]['creativeTypes'] as $key => $value) {
            $countryData = $this->get('CountriesModel')->get_country($value['country_id']);
            $platformData = $this->get('PlatformsModel')->get_platform($value['platform_id']);
            $creativeTypeData = $this->get('CreativesModel')->get_creative_type($value['creative_type_id']);

            $insertionOrderDataFormatted[0]['creativeTypes'][$key]['countryName'] = $countryData[0]['name'];
            $insertionOrderDataFormatted[0]['creativeTypes'][$key]['platformName'] = $platformData['name'];
            $insertionOrderDataFormatted[0]['creativeTypes'][$key]['creativeTypeName'] = $creativeTypeData[0]['label'];
        }

        $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // set default header data
        //$pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE.' 006', PDF_HEADER_STRING);

        // set header and footer fonts
        $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

        // set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        // set margins
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        // set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        // set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        // set font      

        $pdf->AddPage();

        $left_column = '';
        $right_column = '';

        $pdf->SetFillColor(255, 255, 200);
        $pdf->SetTextColor(0, 63, 127);

        $pdf->Write(0, 'Example of independent Multicell() columns', '', 0, 'L', true, 0, false, false, 0);
        $pdf->MultiCell(120, 0, $left_column, 1, 'J', 1, 0, '', '', true, 0, false, true, 0);

        $pdf->Cell(60, 7, 'Pricing Model', 1, 0, 'C', 1);
        $pdf->Cell(60, 7, $insertionOrderDataFormatted[0]['user_data']->firstname, 1, 0, 'C', 1);
        $pdf->Ln();

        $pdf->MultiCell(120, 0, $right_column, 1, 'J', 1, 0, '', '', true, 0, false, true, 0);

        $pdf->lastPage();

        // output the HTML content
//        $pdf->writeHTML($this->render(
//                'AdminIndexBundle:insertionOrders:insertionOrdersFirstPdf.html.twig',
//                array('insertionOrder' => $insertionOrderDataFormatted[0])
//        ), true, false, true, false, '');
//        $pdf->lastPage();

//
//        $pdf->writeHTML($this->render(
//                'AdminIndexBundle:insertionOrders:insertionOrdersSecondPdf.html.twig',
//                array('insertionOrder' => $insertionOrderDataFormatted[0])
//        ), true, false, true, false, '');
//        $pdf->lastPage();

        $pdf->AddPage();

        $pdf->SetFillColor(255, 0, 0);
        $pdf->SetTextColor(255);
        $pdf->SetDrawColor(128, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->SetFont('helvetica', '', 8);

        $pdf->Cell(50, 7, 'Ad Format', 1, 0, 'C', 1);
        $pdf->Cell(30, 7, 'Platform', 1, 0, 'C', 1);
        $pdf->Cell(50, 7, 'Country', 1, 0, 'C', 1);
        $pdf->Cell(20, 7, 'Start Date', 1, 0, 'C', 1);
        $pdf->Cell(20, 7, 'End Date', 1, 0, 'C', 1);
        $pdf->Cell(20, 7, 'Pricing Model', 1, 0, 'C', 1);
        $pdf->Cell(30, 7, 'Impressions to deliver', 1, 0, 'C', 1);
        $pdf->Cell(15, 7, 'Price', 1, 0, 'C', 1);
        $pdf->Cell(15, 7, 'Cost', 1, 0, 'C', 1);

        $pdf->Ln();
        // Color and font restoration
        $pdf->SetFillColor(224, 235, 255);
        $pdf->SetTextColor(0);
        $pdf->SetFont('');

        $fill = 0;
        foreach ($insertionOrderDataFormatted[0]['creativeTypes'] as $row) {
            $pdf->Cell(50, 6, $row['creativeTypeName'], 'LR', 0, 'L', $fill);
            $pdf->Cell(30, 6, $row['platformName'], 'LR', 0, 'R', $fill);
            $pdf->Cell(50, 6, $row['countryName'], 'LR', 0, 'R', $fill);
            $pdf->Cell(20, 6, $row['start_date'], 'LR', 0, 'R', $fill);
            $pdf->Cell(20, 6, $row['end_date'], 'LR', 0, 'R', $fill);
            $pdf->Cell(20, 6, $row['pricing_model_id'], 'LR', 0, 'R', $fill);
            $pdf->Cell(30, 6, $row['impressions_count'], 'LR', 0, 'R', $fill);
            $pdf->Cell(15, 6, $row['price'], 'LR', 0, 'R', $fill);
            $pdf->Cell(15, 6, $row['cost'], 'LR', 0, 'R', $fill);
            $pdf->Ln();
            $fill = !$fill;
        }
        $pdf->Cell(250, 0, '', 'T');

        $pdf->writeHTML($this->render(
            'AdminIndexBundle:insertionOrders:insertionOrdersLastPdf.html.twig',
            array('insertionOrder' => $insertionOrderDataFormatted[0])
        ), true, false, true, false, '');

        $pdf->lastPage();

        if (ob_get_contents()) {
            ob_clean();
        }

        return new Response($pdf->Output('example_006.pdf', 'D'));
    }

    public function get_excelAction()
    {
        $request = Request::createFromGlobals();

        $postData = array();
        $postData['startingRecord'] = $request->request->get('startingRecord');
        $postData['sortField'] = $request->request->get('sortField');
        $postData['sortOrder'] = $request->request->get('sortOrder');
        $postData['itemsPerPage'] = $request->request->get('itemsPerPage');
        $postData['searchTerm'] = $request->request->get('searchTerm');
        $postData['manage_io'] = $request->request->get('manage_io');
        $postData['filters'] = $request->request->get('filters');

        $postData['itemsPerPage'] = (isset($postData['itemsPerPage'])) ? (int)$postData['itemsPerPage'] : self::DEFAULT_ITEMS_PER_PAGE;
        $postData['startingRecord'] = (isset($postData['startingRecord']) and (int)$postData['startingRecord'] >= 0) ? (int)$postData['startingRecord'] : 0;

        if (!empty($_GET['filters'])) {
            $this->filters = json_decode($_GET['filters']);
        } else {
            $this->filters = null;
        }

        $filteredResult = array();
        $this->filtersToApply = array();

        if (!empty($this->filters)) {
            foreach ($this->filters as $filter => $value) {
                if (($value > 0) || ($value != '0')) {
                    if ($filter == 'mediaGroups') {
                        $filter = 'mediaGroupsAgency';
                        $value = $value . ',';
                    }
                    if ($filter == 'mediaAgencies') {
                        $filter = 'mediaGroupsAgency';
                        $value = ',' . $value;
                    }
                    $this->filtersToApply[$filter] = $value;
                }
            }
        }

        try {
            $headerDetails = array(
                'creator' => 'AdServer Admin',
                'modifiedBy' => 'AdServer Admin',
                'title' => 'AdServer Admin',
                'subject' => 'Office 2007 XLSX Document',
                'description' => 'Document for Office 2007 XLSX, generated using PHP classes.',
                'keywords' => 'office 2007 openxml php',
                'category' => 'result file'
            );

            $templatePath = '';
            $template = '';

            $headerData = array(
                'data' => array(
                    array('name' => 'Business Deal #'),
                    array('name' => 'Business Contact'),
                    array('name' => 'Invoicing Entity'),
                    array('name' => 'IO Signature Date'),
                    array('name' => 'Client Name'),
                    array('name' => 'Advertised Brand'),
                    array('name' => 'Advertised Company'),
                    array('name' => 'Campaign Name'),
                    array('name' => 'Company Purchase Order'),
                    array('name' => 'Currency'),
                    array('name' => 'Net IO Amount'),
                    array('name' => 'NET IO Amount - EUR'),
                    array('name' => 'Save Types')
                ),
                'style' => array(
                    'fill' => array('type' => PHPExcel_Style_Fill::FILL_SOLID, 'color' => array('rgb' => '66CFF2')),
                    'borders' => array('bottom' => array('style' => PHPExcel_Style_Border::BORDER_THIN), 'left' => array('style' => PHPExcel_Style_Border::BORDER_THIN), 'right' => array('style' => PHPExcel_Style_Border::BORDER_THIN)),
                    'alignment' => array('horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_JUSTIFY),
                    'font' => array('color' => array('rgb' => '000000'), 'bold' => true,)
                ),
                'cellFormat' => array('width' => 'auto')
            );

            $searchCriteria = array(
                'itemsPerPage' => 0,
                'sortField' => 'i.id',
                'sortOrder' => 'asc',
                'startingRecord' => 0
            );

            $insertionOrders = $this->get('InsertionOrdersModel')->get_list(null, $searchCriteria, $this->filtersToApply);

            $excelData = array();

            foreach ($insertionOrders['items'] as $key => $value) {
                $dealNumber = $value['deal_number_label'];
                if (isset($value['deal_number_extend']) && $value['deal_number_extend'] > 1) {
                    $dealNumber = $value['deal_number_label'] . '-' . $value['deal_number_extend'];
                }
                if ($value['save_type'] == 1) $value['save_type'] = 'Draft';
                if ($value['save_type'] == 2) $value['save_type'] = 'Validated';
                if ($value['save_type'] == 3) $value['save_type'] = 'Cancelled';
                array_push($excelData, array(
                    'data' => array(
                        $dealNumber,
                        $value['user_name'],
                        explode('-', $dealNumber)[1],
                        $value['io_signature_date'],
                        $value['client_name'],
                        $value['advertised_brand'],
                        $value['advertised_company'],
                        $value['campaign_name'],
                        $value['company_purchase_order'],
                        $value['currencyName'],
                        $value['total_net_cost'],
                        $value['eur_total_net_cost'],
                        $value['save_type'],
                    ),
                    'style' => array(
                        'fill' => array('type' => PHPExcel_Style_Fill::FILL_SOLID, 'color' => array('rgb' => 'FFFFFF')),
                        'borders' => array('bottom' => array('style' => PHPExcel_Style_Border::BORDER_THIN), 'left' => array('style' => PHPExcel_Style_Border::BORDER_THIN), 'right' => array('style' => PHPExcel_Style_Border::BORDER_THIN)),
                        'alignment' => array('horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_JUSTIFY),
                        'font' => array('color' => array('rgb' => '000000'))
                    ),
                    'cellFormat' => array('width' => 'auto')
                ));
            }

            $excelDocument = new BusinessDealNumbersExcelExport($templatePath, $template, $headerDetails, $headerData, $excelData, 'Insertion-Orders');
            $excelDocument->createExcel();

            if (ob_get_contents()) {
                ob_clean();
            }
            return new Response($excelDocument->export());
        } catch (Exception $ex) {
            return new Response($ex->getMessage(), '500');
        }
    }

    public function getDioAdFormatsAction()
    {
        global $kernel;

        //Data that is available//
        return new JsonResponse($kernel->getContainer()->get('InsertionOrdersModel')->getDioAdFormats());
    }

    public function get_adv_industryAction()
    {

        $insertionOrderDataFormatted = array();

        $insertionOrderDataFormatted['advertised_brands_industry_sector_categories'] = $this->get('AdvertisedBrandsModel')->get_advertised_brands_industry_sector_categories();
        $insertionOrderDataFormatted['advertised_brands_industry_sectors'] = $this->get('AdvertisedBrandsModel')->get_advertised_brands_industry_sectors();

        return new JsonResponse($insertionOrderDataFormatted);
    }

    private function get_headers()
    {
        $headers = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";
        //$headers .= 'From: Gameloft Advertising Solutions <advertising-solutions@gameloft.com>' . "\r\n";
        return $headers;
    }

    public function resend_notificationAction($data = array())
    {

        $request = Request::createFromGlobals();

        $postData['email_notification'] = $request->request->get('email_notification');
        $insertionOrderDataId = $request->request->get('insertionOrderId');
        $postData['insertionOrderId'] = $request->request->get('insertionOrderId');
        $postData['account_country'] = $request->request->get('account_country');
        $postData['advertised_company'] = $request->request->get('advertised_company');
        $postData['advertised_brand'] = $request->request->get('advertised_brand');
        $postData['io_signature_date'] = $request->request->get('io_signature_date');
        $postData['campaign_name'] = $request->request->get('campaign_name');
        $postData['authorized_sales_name'] = $request->request->get('authorized_sales_name');
        $postData['email'] = $request->request->get('email');
        $postData['currency'] = $request->request->get('currency');
        $postData['amount'] = $request->request->get('amount');
        $postData['deal_number'] = $request->request->get('deal_number');
        $postData['deal_number_label'] = $request->request->get('deal_number_label');
        $postData['deal_number_extend'] = $request->request->get('deal_number_extend');
        $postData['client_id'] = $request->request->get('client_id');
        $postData['adv_prod_serv'] = $request->request->get('adv_prod_serv');
        $postData['games'] = $request->request->get('games');
        $postData['total_cost'] = $request->request->get('total_cost');
        $postData['total_net_cost'] = $request->request->get('total_net_cost');
        $postData['creativeTypes'] = $request->request->get('creativeTypes');
        $postData['deal_number_id'] = $this->get('BusinessDealNumbersModel')->getIdByDealNumber($postData['deal_number'], 1); //
        //$postData['billing_contact_id'] = $_POST['business_contact_id'];
        $postData['advertised_brands_industry_sector_category_id'] = $request->request->get('advertised_brands_industry_sector_category_id');
        $postData['advertised_brands_industry_sector_id'] = $request->request->get('advertised_brands_industry_sector_id');
        $postData['network'] = $request->request->get('network');
        $postData['link'] = $request->request->get('link');
        $postData['ad_format'] = $request->request->get('ad_format');
        $postData['media_agency'] = $request->request->get('media_agency');
        $postData['media_group'] = $request->request->get('media_group');
        $postData['currency_name'] = $request->request->get('currency_name');
        $postData['total_net_cost'] = str_replace("undefined", $postData['currency'], $postData['total_net_cost']);
        $postData['eurExchange'] = $request->request->get('eur_total_net_cost');

        $this->generate_notification($postData);

        return new JsonResponse(array(
            'success' => 'true',
            'message' => 'Notification Sent!'
        ));

    }

    public function resend_happn_notificationAction($data = array())
    {

        $request = Request::createFromGlobals();

        $postData['email_notification'] = $request->request->get('email_notification');
        $insertionOrderDataId = $request->request->get('insertionOrderId');
        $postData['insertionOrderId'] = $request->request->get('insertionOrderId');
        $postData['account_country'] = $request->request->get('account_country');
        $postData['advertised_company'] = $request->request->get('advertised_company');
        $postData['advertised_brand'] = $request->request->get('advertised_brand');
        $postData['io_signature_date'] = $request->request->get('io_signature_date');
        $postData['campaign_name'] = $request->request->get('campaign_name');
        $postData['authorized_sales_name'] = $request->request->get('authorized_sales_name');
        $postData['email'] = $request->request->get('email');
        $postData['currency'] = $request->request->get('currency');
        $postData['amount'] = $request->request->get('amount');
        $postData['deal_number'] = $request->request->get('deal_number');
        $postData['deal_number_label'] = $request->request->get('deal_number_label');
        $postData['deal_number_extend'] = $request->request->get('deal_number_extend');
        $postData['client_id'] = $request->request->get('client_id');
        $postData['adv_prod_serv'] = $request->request->get('adv_prod_serv');
        $postData['games'] = $request->request->get('games');
        $postData['total_cost'] = $request->request->get('total_cost');
        $postData['total_net_cost'] = $request->request->get('total_net_cost');
        $postData['creativeTypes'] = $request->request->get('creativeTypes');
        $postData['deal_number_id'] = $this->get('BusinessDealNumbersModel')->getIdByDealNumber($postData['deal_number'], 1); //
        //$postData['billing_contact_id'] = $_POST['business_contact_id'];
        $postData['advertised_brands_industry_sector_category_id'] = $request->request->get('advertised_brands_industry_sector_category_id');
        $postData['advertised_brands_industry_sector_id'] = $request->request->get('advertised_brands_industry_sector_id');
        $postData['network'] = $request->request->get('network');
        $postData['link'] = $request->request->get('link');
        $postData['ad_format'] = $request->request->get('ad_format');
        $postData['media_agency'] = $request->request->get('media_agency');
        $postData['media_group'] = $request->request->get('media_group');
        $postData['currency_name'] = $request->request->get('currency_name');
        $postData['total_net_cost'] = str_replace("undefined", $postData['currency'], $postData['total_net_cost']);
        $postData['eurExchange'] = $request->request->get('eur_total_net_cost');

        $postData['email_notification'] = $postData;

        $this->generate_Happn_notification($postData);

        return new JsonResponse(array(
            'success' => 'true',
            'message' => 'Notification Sent!'
        ));

    }

    private function generate_notification($mail, $id = 0, $source = null, $adOps = array())
    {

        global $kernel;

        //if($mail['deal_number_extend'] > 1) $mail['deal_number_label'] = $mail['deal_number_label'] . '-' . $mail['deal_number_extend'];

        $subject = " DIO-# " . $mail['deal_number_label'] . (isset($mail['advertised_company']) ? " signed for " . $mail['advertised_company'] . " - " . $mail['advertised_brand'] : '');
        $receiver = 'Alexandre.Tan@gameloft.com';

        $cc = null;

        if ($source == 'changes') {
            $subject = " DIO-# " . $mail['deal_number_label'] . " has been changed ";
            $receiver = implode(",", $mail['io_campaign_managers']);
            $cc = implode(",", $mail['insertion_orders_contacts']);
        }

        //$receiver = 'bogdanmihail.musat@gameloft.com';
        if ($source != 'changes') {
            $copy = $mail['email'];
            $campaign_start = '';
            $campaign_end = '';
            $total_cost = explode(' ', $mail['total_net_cost']);
        } else {
            $copy = $mail['email'];
        }
        //$totalSumInEuro = 0;

        if ($source == 'api') {
            $total_cost = [];
            $mail['ad_format'] = $mail['creativeTypes'];
            $mail['currency_name'] = $mail['currencyName'];
            $mail['authorized_sales_name'] = $mail['adv_client_name'];
            $mail['authorized_sales_title'] = $mail['adv_sales_title'];
            $mail['authorized_client_title'] = $mail['adv_client_title'];
            $mail['authorized_client_name'] = $mail['adv_client_name'];
            $clientData = $kernel->getContainer()->get('ClientsModel')->get_details($mail['client_id']);
            $mail['account_country'] = $kernel->getContainer()->get('CountriesModel')->get_country_name($clientData['country']);
            $mail['advertised_brands_industry_sector_id'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_advertised_brands_industry_sectors($mail['advertised_brands_industry_sector_id']);
            $mail['advertised_brands_industry_sector_category_id'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_advertised_brands_industry_sector_categories($mail['advertised_brands_industry_sector_category_id']);
            if (!empty($mail['mediaGroupsAgency'])) {
                $media = explode(',', $mail['mediaGroupsAgency']);
                $mail['media_agency'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_media_agency($media[1]);
                $mail['media_group'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_media_groups($media[0]);
            }
            $mail['total_net_cost'] = $mail['currency_name'] . ' ' . number_format($mail['total_net_cost']);
            $mail['eurExchange'] = 'EUR ' . number_format($mail['eur_total_net_cost']);

        } else if ($source != 'changes'){
            if (strpos($total_cost[1], ',') == 0) {
                $total_cost[1] = number_format((float)$total_cost[1], 2, '.', ',');
                //$total_cost[1] = number_format($total_cost[1]);
            } else {
                $total_cost[1] = str_replace(',', '', $total_cost[1]);
                $total_cost[1] = number_format((float)$total_cost[1], 2, '.', ',');
                //$total_cost[1] = number_format($total_cost[1]);
                $mail['total_net_cost'] = implode(' ', $total_cost);

                $mail['eurExchange'] = number_format((float)$mail['eurExchange'], 2, '.', ',');
                $mail['eurExchange'] = 'EUR ' . $mail['eurExchange'];
            }
        }


        if ($source == 'changes') {

            $count = 0;

            $message_one = '
            <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;"><a href="https://acq.gameloft.org/adserver/web/insertion-orders/get_edit/' . (isset($mail['id']) ? $mail['id'] : "") . '/" target="_blank">' . (isset($mail['deal_number_label']) ? $mail['deal_number_label'] : "") . '</a>
            <br />
            <br />

            <table>
            <tr>
            <td style="border: 1px solid black; padding: 5px;" >Network</td>
            <td style="border: 1px solid black; padding: 5px;">Old Creative Type</td>
            <td style="border: 1px solid black; padding: 5px;">Creative Type</td>
            <td style="border: 1px solid black; padding: 5px;" >Pricing Model Old</td>
            <td style="border: 1px solid black; padding: 5px;">Pricing Model New</td>
            <td style="border: 1px solid black; padding: 5px;">Items to Deliver Old</td>
            <td style="border: 1px solid black; padding: 5px;">Items to Deliver New</td>
            <td style="border: 1px solid black; padding: 5px;">Unit Price Old</td>
            <td style="border: 1px solid black; padding: 5px;">Unit Price New</td>
            </tr>
           
            ';

            $message_two = '';

            foreach ($mail['ad_format_new'] as $k => $v) {

                if (!isset($v['old_pricing_model'])) continue;
                if ($v['is_free'] == 1) continue;
                $count++;
                //print_r($v);

                switch ($v['pricing_model_id']) {
                    case 10:
                        $v['pricing_model_name'] = 'Package';
                        break;
                    case 11:
                        $v['pricing_model_name'] = 'Fee';
                        break;
                    default:
                        $v['pricing_model_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_pricing_models_name($v['pricing_model_id']);
                }
                switch ($v['old_pricing_model']) {
                    case 10:
                        $v['old_pricing_model_name'] = 'Package';
                        break;
                    case 11:
                        $v['old_pricing_model_name'] = 'Fee';
                        break;
                    default:
                        $v['old_pricing_model_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_pricing_models_name($v['old_pricing_model']);
                }
                if ($source == 'api') {
                    $v['network_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->getNetworkName($v['network']);
                    $v['creative_type_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->getAdFormatName($v['creative_type_id']);
                    $v['country_name'] = '';
                    foreach ($v['country_id'] as $country) {
                        $v['country_name'] .= $kernel->getContainer()->get('CountriesModel')->get_country_name($country) . ', ';
                    }
                    $v['country_name'] = rtrim($v['country_name'], ',');

                    switch ($v['pricing_model_id']) {
                        case 10:
                            $v['pricing_model_name'] = 'Package';
                            break;
                        case 11:
                            $v['pricing_model_name'] = 'Fee';
                            break;
                        default:
                            $v['pricing_model_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_pricing_models_name($v['pricing_model_id']);
                    }
                    $v['price'] = number_format($v['price'], 4);

                    $v['cost'] = number_format($v['cost'], 2);

                    $v['impressions_count'] = str_replace(',', '', $v['impressions_count']);
                    $v['old_unit_price'] = str_replace(',', '', $v['old_unit_price']);
                    $v['price'] = str_replace(',', '', $v['price']);

                    $v['impressions_count'] = number_format((int)$v['impressions_count']);

                }

                if (empty($campaign_start)) $campaign_start = $v['start_date'];
                if (empty($campaign_end)) $campaign_end = $v['end_date'];
                if (isset($v['old_pricing_model_name'])) {

                    $v['creative_type_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->getAdFormatName($v['creative_type_id']);

                    if($v['old_creative_type_id'] != $v['creative_type_id']) {
                        $v['old_creative_type_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->getAdFormatName($v['old_creative_type_id']);
                    }

                    $v['impressions_count'] = (int)str_replace(',', '', $v['impressions_count']);
                    $v['old_impressions'] = (int)str_replace(',', '', $v['old_impressions']);

                    $v['price'] = str_replace(',', '', $v['price']);

                    $message_two .= '
                        <tr>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['network_name'] . '  </td>
                     ';

                    $message_two .= '
                             <td style="'. ($v['old_creative_type_id'] == $v['creative_type_id'] ? "" : "color: red; font-weight: bold;") . ' border: 1px solid black;padding: 5px;">' . (isset($v['old_creative_type_name']) ? $v['old_creative_type_name'] : $v['creative_type_name'] ) . '  </td>
                             <td style="'. (($v['old_creative_type_id'] == $v['creative_type_id']) ? "" : "color: green; font-weight: bold;") . 'border: 1px solid black;padding: 5px;">' . $v['creative_type_name'] . '  </td>
                             <td style="'. (($v['old_pricing_model_name'] == $v['pricing_model_name']) ? "" : "color: red;") . ' border: 1px solid black;padding: 5px;">' . $v['old_pricing_model_name'] . '  </td>
                             <td style="'. (($v['old_pricing_model_name'] == $v['pricing_model_name']) ? "" : "color: green; font-weight: bold;") . 'border: 1px solid black;padding: 5px;">' . $v['pricing_model_name'] . '  </td>
                             <td style="'. (($v['old_impressions'] == $v['impressions_count']) ? "" : "color: red;") . 'border: 1px solid black;padding: 5px;">' . number_format($v['old_impressions'], 0, '.', ',') . '  </td>
                             <td style="'. (($v['old_impressions'] == $v['impressions_count']) ? "" : "color: green; font-weight: bold;") . 'border: 1px solid black;padding: 5px;">' . number_format($v['impressions_count'], 0, '.', ',') . '  </td>
                             <td style="'. (($v['old_unit_price'] == $v['price']) ? "" : "color: red;") . 'border: 1px solid black;padding: 5px;">' . number_format($v['old_unit_price'], 4, '.', ',') . '  </td>
                             <td style="'. (($v['old_unit_price'] == $v['price']) ? "" : "color: green; font-weight: bold;") . 'border: 1px solid black;padding: 5px;">' . number_format($v['price'], 4, '.', ',') . '  </td>
                        </tr>                        
                        
                    ';

                }
            }

            $message = $message_one . $message_two;

            $message .= '</table>';

            if($count == 0) return;

            //print_r($message);die;

        } else {

            $message = '
                <table class="tg" style="font-size: 14px;">
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">DIO Deal Number:  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;"><a href="https://acq.gameloft.org/adserver/web/insertion-orders/get_edit/' . (isset($mail['id']) ? $mail['id'] : "") . '/" target="_blank">' . (isset($mail['deal_number_label']) ? $mail['deal_number_label'] : "") . '</a></td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Advertised Company:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['advertised_company']) ? $mail['advertised_company'] : 0) . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Advertised Brand:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['advertised_brand']) ? $mail['advertised_brand'] : 0) . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Advertised Product or Service:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['adv_prod_serv']) ? $mail['adv_prod_serv'] : 0) . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Industry:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['advertised_brands_industry_sector_id']) ? $mail['advertised_brands_industry_sector_id'] : 0) . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Category:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['advertised_brands_industry_sector_category_id']) ? $mail['advertised_brands_industry_sector_category_id'] : 0) . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">Account Country:  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">' . (isset($mail['account_country']) ? $mail['account_country'] : 0) . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Media Agency:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['media_agency']) ? $mail['media_agency'] : "") . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">Media Group:  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">' . (isset($mail['media_group']) ? $mail['media_group'] : "") . '</td>
                    </tr>

                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 0;">Total Net Cost (Invoice Currency):  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 0;">' . (isset($mail['total_net_cost']) ? $mail['total_net_cost'] : 0) . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">Total Net Cost (Euros):  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">' . (isset($mail['eurExchange']) ? $mail['eurExchange'] : 0) . '</td>
                    </tr>

                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;"></td>
                        <td class="tg-yw4l" style="padding: 0px;">
                            <table style="font-size: 14px;">
                        <thead>
                        <tr>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Network</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Ad Format</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Campaign Country</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Pricing Model</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Unit Price</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Items to deliver</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Cost</strong></th>
                        </tr>
                        </thead>
                        <tbody>';

            foreach ($mail['ad_format'] as $k => $v) {

                if ($source == 'api') {
                    $v['network_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->getNetworkName($v['network']);
                    $v['creative_type_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->getAdFormatName($v['creative_type_id']);
                    $v['country_name'] = '';
                    foreach ($v['country_id'] as $country) {
                        $v['country_name'] .= $kernel->getContainer()->get('CountriesModel')->get_country_name($country) . ', ';
                    }
                    $v['country_name'] = rtrim($v['country_name'], ',');

                    switch ($v['pricing_model_id']) {
                        case 10:
                            $v['pricing_model_name'] = 'Package';
                            break;
                        case 11:
                            $v['pricing_model_name'] = 'Fee';
                            break;
                        default:
                            $v['pricing_model_name'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_pricing_models_name($v['pricing_model_id']);
                    }
                    $v['price'] = number_format($v['price'], 4);

                    $v['cost'] = number_format($v['cost'], 2);

                    $v['impressions_count'] = str_replace(',', '', $v['impressions_count']);

                    $v['impressions_count'] = number_format((int)$v['impressions_count']);

                }

                if (empty($campaign_start)) $campaign_start = $v['start_date'];
                if (empty($campaign_end)) $campaign_end = $v['end_date'];
                $message .= '
                        <tr>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['network_name'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['creative_type_name'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . implode(',', array_unique(explode(',', $v['country_name']))) . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['pricing_model_name'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['price'] . ' ' . $mail['currency_name'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['impressions_count'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['cost'] . ' ' . $mail['currency_name'] . '  </td>
                         </tr>
            ';
            }
            $message .= '
                        </tbody>
                        </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-top: 20px;">Campaign Name: </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-top: 20px;">' . $mail['campaign_name'] . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Contractual Start Date: </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . $campaign_start . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">Contractual End Date: </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">' . $campaign_end . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Business Contact: </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . $mail['authorized_sales_name'] . '</td>
                    </tr>
                </table>
        
        ';

        }

        return $this->send_notification($subject, $message, $receiver, $copy, $id, $source, $cc);

    }

    private function send_notification($subject, $message, $receiver, $copy, $id = 0, $source = null, $cc = null)
    {
        //test commit
        $headers = $this->get_headers();
        $headers .= "From: " . $copy . "\r\n";
        $headers .= "X-Priority: 5 (Lowest)\n";
        $headers .= "X-MSMail-Priority: Lowest\n";
        //$headers .= "Importance: High\n"; yana.gutsman@gameloft.com

        if($cc != null) {
            $headers .= "Cc: $cc,bogdanmihail.musat@gameloft.com, danandrei.moroca@gameloft.com, adrian.calitescu@gameloft.com\r\n"; //
        } else if (in_array(strtolower($copy), array('bogdanmihail.musat@gameloft.com', 'danandrei.moroca@gameloft.com', 'adrian.calitescu@gameloft.com'))) {
            $receiver = 'danandrei.moroca@gameloft.com';
            $headers .= "Cc: bogdanmihail.musat@gameloft.com, danandrei.moroca@gameloft.com, adrian.calitescu@gameloft.com\r\n"; //
        } else if($receiver == 'Alexandre.Tan@gameloft.com') {
            $headers .= "Cc: adrian.ponoran@gameloft.com, bogdanmihail.musat@gameloft.com, danandrei.moroca@gameloft.com, adrian.calitescu@gameloft.com, darina.ponomarenko@gameloft.com, yana.gutsman@gameloft.com, GLPub@gameloft.com\r\n"; //
        }

        $txt = 'The following DIO has been signed:';
        if ($source == 'changes')
            $txt = 'The following DIO has been changed:';

        $msg = '<html lang="en">
            <head>
                <meta charset="utf-8">
            </head>
            <body>
                <div style="background-color: #343434; margin-top: -0.75%; width: 100%; margin-left: -0.7%; height:auto;">
                </div>
                <div >
                    <div >
                        <div style="font-size: 14px;">
                            <p>Hi team,</p>
                            <p>' . $txt . '</p>                            
                            <p>' . $message . '</p>
                            <br />
                            <p style="margin-top: -1%;">Thank you, <br />
                            Gameloft Advertising Solutions</p>
                        </div>
                    </div>
                </div>
            </body>
        </html>';

        mail($receiver, $subject, $msg, $headers);

    }

    public function upload_pdfAction()
    {

        global $kernel;

        if (isset($_POST['checkFiles'])) {
            print_r(scandir($kernel->getContainer()->getParameter('dioPdfUploadPath') . '/2020'));
            die;
        }

        if (!isset($_POST['dio_id'])) {
            return 'Please provide a valid DIO id';
        }

        $data['insertion_order_id'] = $_POST['dio_id'];

        if(isset($_POST['files'])) {
            $files = json_decode($_POST['files'], true);
        } else {
            $files = [];
        }

        $response = array();

        foreach ($files as $key => $file) {

            $file_content = $file['data'];

            if (isset($_POST['api'])) {
                $file_content = str_replace(' ', '+', $file_content);
            }

            if (!isset($_POST['api'])) {
                $file_content = substr($file_content, strpos($file_content, ",") + 1);
            }

            $target_dir_absolute = $kernel->getContainer()->getParameter('dioPdfUploadPath');

            //$target_dir_absolute = "/opt/data/adsfiles/";

            if (isset($_POST['api'])) {

                $deal_label = $kernel->getContainer()->get('InsertionOrdersModel')->get_deal_number_label($_POST['dio_id']);

                $dioYear = explode('-', $deal_label);
                if (isset($dioYear[0])) {
                    $dio_year = $dioYear[0];
                } else {
                    $dio_year = '2020';
                }

                $file['fileName'] = "Insertion_order-$deal_label";

            } else {
                $dio_year = $_POST['dio_year'];
            }

            $this->makeDir($target_dir_absolute . $dio_year);

            $target_dir = $target_dir_absolute . $dio_year . '/';

            $target_file = $target_dir . basename($file['fileName'] . '-' . date('h-m-s') . '.' . $file['file_extension']);

            if (isset($_POST['api'])) {
                $target_file = $target_dir . basename($file['fileName'] . '-Salesforce-Signed.' . $file['file_extension']);
            }

            $data['path'] = $target_file;

            $uploadOk = 1;

            // Check file size
            if ($file['file_size'] > 7340032) {
                $response['message'] = "Sorry, your file is too large";
                $uploadOk = 0;
            }

            // Allow certain file formats
            $validation = explode('.', $target_file);
            $validationFile = end($validation);
            $validationFile = strtolower($validationFile);

            if (!in_array($validationFile, array('jpg', 'png', 'jpeg', 'gif', 'pdf', 'xls', 'xlsx', 'msg'))) {
                $response['message'] = "Sorry, only JPG, JPEG, PNG, PDF, GIF, MSG, XLS & XLSX files are allowed";
                $uploadOk = 0;
            }

            // Check if $uploadOk is set to 0 by an error
            if ($uploadOk == 1) {

                if(base64_decode($file_content, true) == true) {
                    $file_content = base64_decode($file_content, true);
                }

                file_put_contents($target_file, $file_content);

                if (isset($_POST['api'])) {

                    $dioData = $this->get_listAction($data['insertion_order_id'], 'api_pdf_save');

                    if (empty($dioData)) return;

                    $dioData = $dioData[0];

                    if (isset($dioData['save_type']) && $dioData['save_type'] == 1) {

                        $kernel->getContainer()->get('InsertionOrdersModel')->update_dio($data['insertion_order_id'],
                            array(
                                "save_type" => 2
                            )
                        );

//                        $salesForceCurl = $this->curl_sales_force($data['insertion_order_id']);
//
//                        if (empty($salesForceCurl)) {
//
//                            $this->generate_notification($dioData, 0, 'api');
//
//                        } else {
//
//                            $this->generate_notification($dioData, 0, 'api');
//
//                        }

                    }

                }

                if (isset($_POST['api'])) {
                    $checkFilePatch = $kernel->getContainer()->get('InsertionOrdersModel')->check_signed_dio($target_file, $data['insertion_order_id']);
                    if (empty($checkFilePatch)) {
                        $response['signed_id'] = $kernel->getContainer()->get('InsertionOrdersModel')->put_signed_dio($data);
                    }
                } else {
                    $response['signed_id'] = $kernel->getContainer()->get('InsertionOrdersModel')->put_signed_dio($data);
                }

                $response['signed_id_list'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_signed_dio($data['insertion_order_id']);

                $response['uploadOk'] = $uploadOk;
            }
        };

        if (isset($_POST['api'])) {
            return $response;
        }

        return new JsonResponse($response);

    }

    public function download_pdfAction($link = array())
    {

        $fileurl = base64_decode($link);
        $fileArray = explode('/', $fileurl);
        $fileName = array_pop($fileArray);
        $remoteImage = $fileurl;
        $imginfo = getimagesize($remoteImage);
        header('Content-Disposition: inline; filename=' . $fileName);
        header("Content-type: {$imginfo['mime']}");
        readfile($fileurl);
        exit;
    }

    public function remove_pdfAction()
    {

        $filepath = '';

        $dios = $this->get('InsertionOrdersModel')->get_signed_dio($_POST['dio_id']);

        foreach ($dios as $dio) {

            if ($dio['id'] == $_POST['id']) {

                $filepath = $dio['path'];

            }

        }

        $response['signed_id_list'] = $this->get('InsertionOrdersModel')->delete_signed_dio($_POST['id'], $_POST['dio_id']);

        $this->get('HistoryInsertionOrdersModel')->remove_file($_POST['dio_id'], $filepath);

        return new JsonResponse($response);

    }

    public function getNetworksAction()
    {
        return new JsonResponse($this->get('InsertionOrdersModel')->getNetworks());
    }

    public function get_purchase_ordersAction()
    {
        return new JsonResponse($this->get('InsertionOrdersModel')->get_purchase_orders());
    }

    public function makeDir($path)
    {
        return is_dir($path) || mkdir($path, 0755, true);
    }

    public function get_network_exchangeAction()
    {

        global $kernel;

        $network_currency = 'USD';

        $networksFloorsResponseErrors = [];
        $networksFloorsResponseErrors[0] = "";

        $network_floor_exchange_parity = 1;

        $request = Request::createFromGlobals();

        $networkValidation = $request->request->get('networkValidation');

        foreach ($networkValidation as $k => $network) {

            $network_floors = $kernel->getContainer()->get('InsertionOrdersModel')->getNetworkFloors($network['network']);
            $network_name = $kernel->getContainer()->get('InsertionOrdersModel')->getNetworkName($network['network']);
            $platform_id = $network['platform_id'];
            $countries = $network['country'];
            $currency = $network['currencyName'];

            $dio_price = $network['price'];

            if ($network['currencyName'] != 'USD') { //change to USD after testing
                $network_floor_exchange_parity = $kernel->getContainer()->get('InsertionOrdersModel')->getExchangeRates(
                    $network['currencyName'],
                    $network_currency,
                    $network['date'],
                    str_replace(",", "", 1)
                );
            }

            $network_floor_exchange = $dio_price * $network_floor_exchange_parity;

            $min_android_rate = 0;

            $min_ios_rate = 0;

            $countryId = 0;

            foreach ($network_floors as $nIndex => $nValue) {
                if (in_array($nValue['country_id'], $countries)) {
                    if ($min_android_rate == 0) {
                        $min_android_rate = $nValue['android_rate'];
                        $countryId = $nValue['country_id'];
                    } else if ($min_android_rate > $nValue['android_rate']) {
                        $min_android_rate = $nValue['android_rate'];
                        $countryId = $nValue['country_id'];
                    }
                    if ($min_ios_rate == 0) {
                        $min_ios_rate = $nValue['ios_rate'];
                        $countryId = $nValue['country_id'];
                    } else if ($min_ios_rate > $nValue['ios_rate']) {
                        $min_ios_rate = $nValue['ios_rate'];
                        $countryId = $nValue['country_id'];
                    }
                }
            }

            if (($min_android_rate != $min_ios_rate) && (in_array(9, $platform_id) || count($platform_id) > 1)) {
                $networksFloorsResponseErrors[] = "For $network_name network, please create different lines for each platform and set the prices separately";
                return new JsonResponse($networksFloorsResponseErrors);
            }

            if (count($network_floors) > 0) {
                foreach ($countries as $cIndex => $cValue) {
                    //print_r($cValue);
                    if (isset($network_floors[intval($cValue)])) {

                        //print_r($cValue);

                        //$country_name = $this->get('CountriesModel')->get_country_name($network_floors[$cValue]['country_id']);
                        $country_name = $kernel->getContainer()->get('CountriesModel')->get_country_name($countryId);

                        $percentage = 100;

                        if ($network['pricing_model_id'] == 6) {
                            $percentage = 80;
                            $network_floor_exchange = $network_floor_exchange * 1000;
                        }

                        $android_rate = $min_android_rate;
                        $ios_rate = $min_ios_rate;

                        $dio_android_margin = ($android_rate * 1 * 130) / 100;
                        $dio_android_margin = number_format($dio_android_margin, 2, '.', ',');

                        $dio_ios_margin = ($ios_rate * 1 * 130) / 100;
                        $dio_ios_margin = number_format($dio_ios_margin, 2, '.', ',');

                        $android_margin = (($network_floor_exchange - $android_rate) / $android_rate * $percentage) < 30;
                        $ios_margin = (($network_floor_exchange - $ios_rate) / $ios_rate * $percentage) < 30;

//                            print_r($network_floor_exchange);
//                            echo '<br />';
//                            print_r($nValue['ios_rate']);
//                            echo '<br />';
//                            print_r((($network_floor_exchange - $nValue['ios_rate']) / $nValue['ios_rate'] * $percentage));
//                            echo '<br />';
//                            print_r($network['pricing_model_id']);
//                            echo '<br />';
//                            print_r($platform_id);
//                            echo '<br />';
//                            print_r($ios_margin);
//                            echo '<br />';die;

                        if ($network['pricing_model_id'] == 6) {
                            $dio_android_margin = $dio_android_margin / 1000;
                            $dio_ios_margin = $dio_ios_margin / 1000;
                        }

                        if (in_array(9, $platform_id) || count($platform_id) > 1) {
                            if ($android_margin && (in_array(2, $platform_id) || in_array(9, $platform_id))) {
                                $networksFloorsResponseErrors[] = "For $network_name network $country_name country 'IOS / Android' platform you are able to set only the price greater than $dio_android_margin USD";
                            }
                            break;
                        }

                        if ($android_margin && (in_array(2, $platform_id) || in_array(9, $platform_id))) {
                            $networksFloorsResponseErrors[] = "For $network_name network $country_name country 'Android' platform you are able to set only the price greater than $dio_android_margin USD";
                        }

                        if ($ios_margin && (in_array(1, $platform_id) || in_array(9, $platform_id))) {
                            $networksFloorsResponseErrors[] = "For $network_name network $country_name country 'IOS' platform you are able to set only the price greater than $dio_ios_margin USD";
                        }

                        break;

                    } else {

                        $country_name = $kernel->getContainer()->get('CountriesModel')->get_country_name($cValue);
                        $networksFloorsResponseErrors[0] .= "$country_name country doesn't have a standard agreed price floor.<br />"; //Price should be more than $dio_margin $currency

                        $percentage = 100;

                        if ($network['pricing_model_id'] == 6) {
                            $percentage = 80;
                            $network_floor_exchange = $network_floor_exchange * 1000;
                        }

                        if ($network_floor_exchange < 1) {
                            $network_floor_exchange = 1;
                        }

                        if ($network_floor_exchange_parity < 1) {
                            $network_floor_exchange_parity = 1;
                        }

                        if ($dio_price < 1) {
                            $dio_price = 1;
                        }

                        $dio_margin = ($dio_price * $network_floor_exchange_parity * 100) / 100;

                        $dio_margin = number_format($dio_margin, 2, '.', ',');

                        $margin = (($network_floor_exchange - 1) / 1 * $percentage) < 30;

                        if ($margin) {
                            $networksFloorsResponseErrors[] = "For $network_name network $country_name country you are able to set only the price greater than $dio_margin $currency";
                        }
                    }
                }
            }
        }
        return new JsonResponse($networksFloorsResponseErrors);
    }

    private function generate_Happn_notification($postData, $id = 0)
    {

        global $kernel;

        $mail = $postData['email_notification'];

        //print_R($mail);
        //test commit
        $headers = $this->get_headers();
        $headers .= "From: " . $mail['email'] . "\r\n";
        $headers .= "X-Priority: 5 (Lowest)\n";
        $headers .= "X-MSMail-Priority: Lowest\n";
        //$headers .= "Importance: High\n"; yana.gutsman@gameloft.com

        $subject = " DIO-# " . $mail['deal_number_label'] . " signed for " . $mail['advertised_company'] . " - " . $mail['advertised_brand'];

        //For Prod
        //$receiver = 'yann.boulanger@happn.com, margot.de-cours@happn.com, marine.ravinet@happn.fr, anne.dauvigny@happn.fr, bertrand.humblot@happn.fr';
        //$headers .= "Cc: romain.devichi@gameloft.com, Yana.Gutsman@gameloft.com, DanAndrei.Moroca@gameloft.com, adrian.calitescu@gameloft.com\r\n"; //
        //For Prod

        if (in_array(strtolower($mail['email']), array('bogdanmihail.musat@gameloft.com', 'danandrei.moroca@gameloft.com', 'adrian.calitescu@gameloft.com'))) {
            $receiver = 'danandrei.moroca@gameloft.com';
            $headers .= "Cc: bogdanmihail.musat@gameloft.com, danandrei.moroca@gameloft.com, adrian.calitescu@gameloft.com\r\n"; //
        } else {
            $receiver = 'yann.boulanger@happn.com, margot.de-cours@happn.com, marine.ravinet@happn.fr, anne.dauvigny@happn.fr, bertrand.humblot@happn.fr';
            $headers .= "Cc: darina.ponomarenko@gameloft.com, romain.devichi@gameloft.com, Yana.Gutsman@gameloft.com, DanAndrei.Moroca@gameloft.com, adrian.calitescu@gameloft.com\r\n"; //
        }

        //for testing
        //$receiver = 'danandrei.moroca@gameloft.com';
        //$headers .= "Cc: bogdanmihail.musat@gameloft.com, danandrei.moroca@gameloft.com, adrian.calitescu@gameloft.com\r\n"; //
        //for testing

        $campaign_start = '';
        $campaign_end = '';
        $total_cost = explode(' ', $mail['total_net_cost']);

        if (strpos($total_cost[1], ',') == 0) {
            $total_cost[1] = number_format((float)$total_cost[1], 2, '.', ',');
            //$total_cost[1] = number_format($total_cost[1]);
        } else {
            $total_cost[1] = str_replace(',', '', $total_cost[1]);
            $total_cost[1] = number_format((float)$total_cost[1], 2, '.', ',');
            //$total_cost[1] = number_format($total_cost[1]);
        }

        $total_net_cost = 0;

        foreach ($mail['ad_format'] as $k => $v) {
            if ($v['network'] == 2) {

                $v['net_cost'] = str_replace(',', '', $v['net_cost']);

                $total_net_cost += $v['net_cost'];

            }
        }

        $total_net_cost = number_format($total_net_cost, 2, '.', ',');

        //($fromCurrency, $toCurrency, $date, $value = null)
        $temp_total_net_cost = str_replace(',', '', $total_net_cost);

        $eurExchange = $kernel->getContainer()->get('InsertionOrdersModel')->getExchangeRates($mail['currency_name'], 'EUR', $mail['currency_name'], $temp_total_net_cost);

        $eurExchange = str_replace(',', '', $eurExchange);

        $eurExchange = number_format($eurExchange, 2, '.', ',');

        $eurExchange = "EUR $eurExchange";

        //isset($var) ?: $var = "";

        $message = '
                <table class="tg" style="font-size: 14px;">
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">DIO Deal Number:  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">' . (isset($mail['deal_number_label']) ? $mail['deal_number_label'] : "") . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Advertised Company:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['advertised_company']) ? $mail['advertised_company'] : "") . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Advertised Brand:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['advertised_brand']) ? $mail['advertised_brand'] : "") . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Advertised Product or Service:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['adv_prod_serv']) ? $mail['adv_prod_serv'] : "") . '</td>
                    </tr>  
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Industry:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['advertised_brands_industry_sector_id']) ? $mail['advertised_brands_industry_sector_id'] : "") . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Category:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['advertised_brands_industry_sector_category_id']) ? $mail['advertised_brands_industry_sector_category_id'] : "") . '</td>
                    </tr>      
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">Account Country:  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">' . (isset($mail['account_country']) ? $mail['account_country'] : "") . '</td>
                    </tr>
                    
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Media Agency:  </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . (isset($mail['media_agency']) ? $mail['media_agency'] : "") . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">Media Group:  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">' . (isset($mail['media_group']) ? $mail['media_agency'] : "") . '</td>
                    </tr>
                    
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 0;">Total Net Cost (Invoice Currency):  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 0;">' . $mail['currency_name'] . ' ' . (isset($total_net_cost) ? $total_net_cost : 0) . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">Total Net Cost (Euros):  </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">' . (isset($eurExchange) ? $eurExchange : 0) . '</td>
                    </tr>

                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;"></td>
                        <td class="tg-yw4l" style="padding: 0px;">
                            <table style="font-size: 14px;">
                        <thead>
                        <tr>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Network</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Ad Format</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Campaign Country</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Pricing Model</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Unit Price</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Items to deliver</strong></th>
                            <th style="border: 1px solid black;padding: 5px;"><strong>Cost</strong></th>
                        </tr>
                        </thead>
                        <tbody>';

        foreach ($mail['ad_format'] as $k => $v) {

            if ($v['network'] == 2) {
                if (empty($campaign_start)) $campaign_start = $v['start_date'];
                if (empty($campaign_end)) $campaign_end = $v['end_date'];
                $message .= '
                        <tr>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['network_name'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['creative_type_name'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . implode(',', array_unique(explode(',', $v['country_name']))) . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['pricing_model_name'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['price'] . ' ' . $mail['currency_name'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['impressions_count'] . '  </td>
                             <td style="border: 1px solid black;padding: 5px;">' . $v['cost'] . ' ' . $mail['currency_name'] . '  </td>
                         </tr>
            ';
            }
        }
        $message .= '
                        </tbody>
                        </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-top: 20px;">Campaign Name: </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-top: 20px;">' . $mail['campaign_name'] . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Contractual Start Date: </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . $campaign_start . '</td>
                    </tr>
                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">Contractual End Date: </td>
                        <td class="tg-yw4l" style="padding: 0px;padding-bottom: 15px;">' . $campaign_end . '</td>
                    </tr>

                    <tr>
                        <td class="tg-yw4l" style="padding: 0px;">Business Contact: </td>
                        <td class="tg-yw4l" style="padding: 0px;">' . $mail['authorized_sales_name'] . '</td>
                    </tr>
                </table>
        
        ';

        $msg = '<html lang="en">
            <head>
                <meta charset="utf-8">
            </head>
            <body>
                <div style="background-color: #343434; margin-top: -0.75%; width: 100%; margin-left: -0.7%; height:auto;">
                </div>
                <div >
                    <div >
                        <div style="font-size: 14px;">
                            <p>Hi team,</p>
                            <p>The following DIO has been signed:</p>                            
                            <p>' . $message . '</p>
                            <br>
                            <p style="margin-top: -1%;">Thank you, <br>
                            Gameloft Advertising Solutions</p>
                        </div>
                    </div>
                </div>
            </body>
        </html>';

        mail($receiver, $subject, $msg, $headers);

    }

    public function get_campaigns_pricing_models()
    {
        global $kernel;

        //Data that is available
        $data = $kernel->getContainer()->get('CampaignsModel')->get_campaigns_pricing_models(null, null);

        $Package = [
            "id" => "10",
            "name" => "Package",
        ];

        $Fee = [
            "id" => "11",
            "name" => "Fee",
        ];

        $data[] = $Package;
        $data[] = $Fee;

        return json_encode($data);

    }

    public function get_media()
    {
        global $kernel;

        $mediaGroups = $kernel->getContainer()->get('InsertionOrdersModel')->get_media_groups();

        $mediaAgencies = $kernel->getContainer()->get('InsertionOrdersModel')->get_media_agency();

        foreach ($mediaGroups as &$mediaGroup) {
            $mediaGroup['agencies'] = [];
            foreach ($mediaAgencies as $mediaAgency) {
                if ($mediaAgency['media_group_id'] === $mediaGroup['id'])
                    $mediaGroup['agencies'][] = $mediaAgency;
            }
        }

        return json_encode($mediaGroups);

    }

    public function get_dio_pdf($dioId)
    {
        global $kernel;

        $dio_data = $kernel->getContainer()->get('InsertionOrdersModel')->getDioPDF($dioId);

        if (count($dio_data) > 0) {

            $zip = new \ZipArchive();

            $zipFile = "/opt/data/adsfiles/salesforce/embedded.zip";

            if ($zip->open($zipFile, \ZipArchive::CREATE) !== TRUE) {
                exit("cannot open <$zipFile>\n");
            }

            foreach ($fileStructure as $dirFile) {
                $dirFileDestination = str_replace($folderPath . '/', '', $dirFile);
                if (!is_dir($dirFile)) {
                    $zip->addFile($dirFile, $dirFileDestination);
                }
            }

            $zip->close();

        }

    }

    public function get_dio_timestamp($dio_id)
    {
        if ($dio_id == null || $dio_id == "") {
            return json_encode(array(
                'success' => false,
                'message' => "Please provide a valid DIO ID!",
            ));
        } else {
            global $kernel;
            return json_encode($kernel->getContainer()->get('InsertionOrdersModel')->get_last_modification($dio_id));
        }
    }

    public function generate_dio_pdf($dioData, $type = 0)
    {

        global $kernel;

        if (isset($dioData[0])) {
            $dio_data = $dioData[0];
        } else {
            $dio_data = $dioData;
        }

        $userName = $kernel->getContainer()->get('UsersModel')->get_user_details($dio_data['user_id']);

        $invoicingEntity = $kernel->getContainer()->get('InvoicingEntitiesModel')->get_list($dio_data['invoicing_entity_id'], null)['items'][0];

        $clientData = $kernel->getContainer()->get('ClientsModel')->get_details($dio_data['client_id']);

        $billingContactData = $kernel->getContainer()->get('ClientsModel')->get_billing_contacts($dio_data['client_id'], $dio_data['billing_contact_id']);

        if (count($billingContactData) > 0) {
            $billingContact = $billingContactData[0];
        } else {
            $billingContact = [];
        }

        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        $pdf->SetFont('arial', '', 10);

        //$pdf->SetFont('dejavusans', '', 10);

        $pdf->AddPage();

        if (isset($dio_data['currency']) && is_numeric($dio_data['currency'])) {
            $dio_data["currencyName"] = $kernel->getContainer()->get('CurrenciesModel')->get_details($dio_data['currency']);
            $dio_data['currencyName'] = $dio_data['currencyName']['short_name'];
        } else {
            $dio_data["currencyName"] = $dio_data['currency'];
        }
        if (!isset($dio_data["advertised_brand"])) {
            $dio_data["advertised_brand"] = $dio_data['advertising_brand'];
        }

        if (!isset($dio_data["adv_sales_name"])) $dio_data["adv_sales_name"] = $dio_data['authorized_sales_name'];
        if (!isset($dio_data["adv_sales_title"])) $dio_data["adv_sales_title"] = $dio_data['authorized_sales_title'];

        if (!isset($dio_data["adv_client_name"])) $dio_data["adv_client_name"] = $dio_data['authorized_client_name'];
        if (!isset($dio_data["adv_client_title"])) $dio_data["adv_client_title"] = $dio_data['authorized_client_title'];

        if (isset($_SERVER['SERVER_ADDR']) && ip2long($_SERVER['SERVER_ADDR']) != ip2long('127.0.0.1')) {
            $GLlogo = 'https://acq.gameloft.org/adserver/web/images/GLads_Logo_print.png';
        } else {
            $GLlogo = '';
            //$GLlogo = 'C:\Users\bogdanmihail.musat\Documents\ges\gold\acq\documents\adserver\web\images\GLads_Logo_print.png';
        }

        $dio_data["total_cost"] = (float)$dio_data["total_cost"];

        $html = '
        
       <!DOCTYPE html>
<style>
    .t1-left {
        width: 110px;
        padding-right: 20px
    }

    .t1-right {
        width: 160px;
        display: block;
    }
    
    .tg-9hbo {
        font-weight: bold;
        text-align: left;
    }

    .t2-left {
        width: 116px;
    }

    .t2-right {
        width: 157px;
        display: block;
    }
    .border th, .border tr, .border td {
        border: 1px solid #999c9e;
    }
    .tg-yw4l {
        font-size: 10px;
    }
    table thead {
        text-align: center;
    }

</style>

<table>
    <tr>
        <td style="width:130px">
            <img src="' . $GLlogo . '" style="width:130px; position:relative;"/>
        </td>
        <td style="line-height: 60px; float:left;font-weight: bold; font-size: 14px;width: 330px;">Gameloft Insertion Order ' . $dio_data['deal_number_label'] . '</td>
    </tr>
</table>

<table style="border-bottom: 1px dashed #999999; padding-bottom: 10px; font-size: 8px;">
    <tr>
        <th>
            <table style="position: relative; float:left">

                <tr>
                    <th class="t1-left">Business Contact Name:</th>
                    <th class="t1-right">' . $userName->firstname . ' ' . $userName->lastname . '</th>
                </tr>

                <tr>
                    <td class="t1-left">Phone Number:</td>
                    <td class="t1-right">' . $userName->phone_number . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Email Address:</td>
                    <td class="t1-right">' . $userName->email . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Invoicing Entity:</td>
                    <td class="t1-right">' . (isset($invoicingEntity['invoicing_entity_name']) ? $invoicingEntity['invoicing_entity_name'] : "") . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Billing Contact Email:</td>
                    <td class="t1-right">' . (isset($invoicingEntity['invoicing_entity_email']) ? $invoicingEntity['invoicing_entity_email'] : "") . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Billing Contact Telephone:</td>
                    <td class="t1-right">' . (isset($invoicingEntity['invoicing_entity_phone']) ? $invoicingEntity['invoicing_entity_phone'] : "") . '</td>
                </tr>

                <tr>
                    <td class="t1-left">IO Signature Date:</td>
                    <td class="t1-right">' . (isset($dio_data["io_signature_date"]) ? $dio_data["io_signature_date"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Advertising Company Name:</td>
                    <td class="t1-right">' . (isset($dio_data["advertised_company"]) ? $dio_data["advertised_company"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Advertised Brand Name:</td>
                    <td class="t1-right">' . (isset($dio_data["advertised_brand"]) ? $dio_data["advertised_brand"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Advertised "Product/Service":</td>
                    <td class="t1-right">' . (isset($dio_data["adv_prod_serv"]) ? $dio_data["adv_prod_serv"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Campaign Name:</td>
                    <td class="t1-right">' . (isset($dio_data["campaign_name"]) ? $dio_data["campaign_name"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Business Deal Number:</td>
                    <td class="t1-right">' . (isset($dio_data["deal_number_label"]) ? $dio_data["deal_number_label"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t1-left">Company Purchase Order:</td>
                    <td class="t1-right">' . (isset($dio_data["company_purchase_order"]) ? $dio_data["company_purchase_order"] : 0) . '</td>
                </tr>

            </table>
        </th>

        <th>
            <table style="border-left: 1px dashed #999999; padding-left: 10px; position: relative; float:right">

                <tr>
                    <th class="t2-left">Billing Partner:</th>
                    <th class="t2-right">' . (isset($clientData["name"]) ? addslashes($clientData["name"]) : 0) . '</th>
                </tr>

                <tr>
                    <td class="t2-left">Billing Address:</td>
                    <td class="t2-right">' . (isset($clientData["billing_address"]) ? $clientData["billing_address"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">State:</td>
                    <td class="t2-right">' . (isset($clientData["state"]) ? $clientData["state"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">Zip Code:</td>
                    <td class="t2-right">' . (isset($clientData["zip_code"]) ? $clientData["zip_code"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">City:</td>
                    <td class="t2-right">' . (isset($clientData["city"]) ? $clientData["city"] : 0) . '</td>
                </tr>

                <tr style="border-bottom:1px dashed #999999; padding-bottom:10px;">
                    <td class="t2-left">Country:</td>
                    <td class="t2-right">' . (isset($clientData["country"]) ? $this->numeric_check('country', $clientData["country"]) : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">VAT Number:</td>
                    <td class="t2-right">' . (isset($clientData["vat_number"]) ? $clientData["vat_number"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">Business Contact Name:</td>
                    <td class="t2-right">' . (isset($billingContact["business_contact_name"]) ? $billingContact["business_contact_name"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">Phone Number:</td>
                    <td class="t2-right">' . (isset($billingContact["phone_number"]) ? $billingContact["phone_number"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">Email Address:</td>
                    <td class="t2-right">' . (isset($billingContact["email_address"]) ? $billingContact["email_address"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">Accounting Contact:</td>
                    <td class="t2-right">' . (isset($billingContact["accounting_contact"]) ? $billingContact["accounting_contact"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">Accounting Phone Number:</td>
                    <td class="t2-right">' . (isset($billingContact["accounting_phone_number"]) ? $billingContact["accounting_phone_number"] : 0) . '</td>
                </tr>

                <tr>
                    <td class="t2-left">Accounting Email Address:</td>
                    <td class="t2-right">' . (isset($billingContact["accounting_email_address"]) ? $billingContact["accounting_email_address"] : 0) . '</td>
                </tr>


            </table>
        </th>
    </tr>
</table>

<table style="padding-top: 10px; padding-bottom: 10px; border-bottom:1px dashed #999999;">

    <tr>
        <th>
            Net IO Amount: ' . number_format($dio_data["total_cost"]) . ' ' . (isset($dio_data["currencyName"]) ? $dio_data["currencyName"] : "") . '
        </th>
    </tr>

</table>

<table class="table table-striped table-bordered table-hover resultTable border" id="creativeTypes" style="padding-top: 10px; font-size: 7px; border: 1px solid #999c9e">
    <thead style="font-weight: bold;">
    <tr>
        <th style="font-weight: bold; text-align: center;">Network</th>
        <th style="font-weight: bold; text-align: center;">Ad Format</th>
        <th style="font-weight: bold; text-align: center;">Game</th>
        <th style="font-weight: bold; text-align: center;">Platform</th>
        <th style="font-weight: bold; text-align: center;">Country</th>
        <th style="font-weight: bold; text-align: center;">Start Date</th>
        <th style="font-weight: bold; text-align: center;">End Date</th>
        <th style="font-weight: bold; text-align: center;">Pricing Model</th>
        <th style="font-weight: bold; text-align: center;">Items to deliver</th>
        <th style="font-weight: bold; text-align: center;">Price</th>
        <th style="font-weight: bold; text-align: center;">Cost</th>
    </tr>
    </thead>

    <tbody>
';

        if (isset($dio_data['creativeTypes'])) {

            if ($type == 2) {
                $summarizedArray = [];
                $maxPrice = [];
                $key = '';

                foreach ($dio_data['creativeTypes'] as $creativeKey => $creativeValue) {

                    $key = $creativeValue['creative_type_id'] . '-' . $creativeValue['pricing_model_id'];

                    if (!isset($summarizedArray[$key])) {
                        $summarizedArray[$key] = [];
                        $maxPrice[$key] = [];
                        $maxPrice[$key]['free'] = [];
                        $maxPrice[$key]['free']['price'] = [];
                        $maxPrice[$key]['not_free'] = [];
                        $maxPrice[$key]['not_free']['price'] = [];
                        $summarizedArray[$key]['creative_type_id'] = $creativeValue['creative_type_id'];
                        if (!isset($creativeValue['platform_id']))
                            $summarizedArray[$key]['platform_name'] = $creativeValue['platform_name'];
                        $summarizedArray[$key]['gamesSelect'] = 'All Available Games';
                        $summarizedArray[$key]['end_date'] = $creativeValue['end_date'];
                        $summarizedArray[$key]['start_date'] = $creativeValue['start_date'];
                        $summarizedArray[$key]['pricing_model_id'] = $creativeValue['pricing_model_id'];
                        $summarizedArray[$key]['cost'] = 0;
                        $summarizedArray[$key]['impresions'] = 0;
                        $summarizedArray[$key]['network'] = $creativeValue['network'];
                        $summarizedArray[$key]['free_impresions'] = 0;
                        $summarizedArray[$key]['summarised_is_free'] = 0;
                        $summarizedArray[$key]['is_free'] = 0;
                        $summarizedArray[$key]['country_id'] = [];
                        $summarizedArray[$key]['price'] = $creativeValue['price'];
                    }

                    if ($creativeValue['is_free'] == 0) $maxPrice[$key]['not_free']['price'] = $creativeValue['price'];
                    if ($creativeValue['is_free'] == 1) $maxPrice[$key]['not_free']['price'] = $creativeValue['price'];

                    if ($summarizedArray[$key]['start_date'] > $creativeValue['start_date']) {
                        $summarizedArray[$key]['start_date'] = $creativeValue['start_date'];
                    }

                    if ($summarizedArray[$key]['end_date'] < $creativeValue['end_date']) {
                        $summarizedArray[$key]['end_date'] = $creativeValue['end_date'];
                    }

                    foreach ($creativeValue['country_id'] as $k => $tempCountry) {
                        $summarizedArray[$key]['country_id'][] = $kernel->getContainer()->get('CountriesModel')->get_country_name($tempCountry);
                    }

                    $summarizedArray[$key]['country_id'] = array_unique($summarizedArray[$key]['country_id']);

                    switch ($creativeValue['pricing_model_id']) {
                        case '10':
                            $pricingModel = 'Package';
                            break;
                        case '11':
                            $pricingModel = 'Fee';
                            break;
                        default:
                            $pricingModel = $kernel->getContainer()->get('InsertionOrdersModel')->get_pricing_models_name($creativeValue['pricing_model_id']);
                    }

                    $platformsNames = [];

                    if (is_array($creativeValue['platform_id'])) {
                        foreach ($creativeValue['platform_id'] as $k => $tempPlf) {
                            if (empty($tempPlf)) break;
                            if ($tempPlf == 9) {
                                $summarizedArray[$key]['platform_id'][] = 'All Platforms';
                                break;
                            } else if ($tempPlf == 10) {
                                $summarizedArray[$key]['platform_id'][] = 'Not Applicable';
                                break;
                            } else {
                                $summarizedArray[$key]['platform_id'][] = $kernel->getContainer()->get('InsertionOrdersModel')->getPlatformName($tempPlf);
                                break;
                            }
                        };
                    } else {
                        if ($creativeValue['platform_id'] == 9) {
                            $summarizedArray[$key]['platform_id'] = 'All Platforms';
                        } else if ($creativeValue['platform_id'] == 10) {
                            $summarizedArray[$key]['platform_id'] = 'Not Applicable';
                        }
                    }

                    switch ($creativeValue['pricing_model_id']) {
                        case '10':
                            $summarizedArray[$key]['pricing_model'] = 'Package';
                            break;
                        case '11':
                            $summarizedArray[$key]['pricing_model'] = 'Fee';
                            break;
                        default:
                            $summarizedArray[$key]['pricing_model'] = $kernel->getContainer()->get('InsertionOrdersModel')->get_pricing_models_name($creativeValue['pricing_model_id']);
                    }

                    //print_r($creativeValue);

                    if ($creativeValue['is_free'] != 1) {

                        if (!isset($summarizedArray[$key]['impresions'])) $summarizedArray[$key]['impresions'] = 0;
                        if (!isset($summarizedArray[$key]['cost'])) $summarizedArray[$key]['cost'] = 0;
                        $summarizedArray[$key]['impresions'] = $summarizedArray[$key]['impresions'] + str_replace('.', '', $creativeValue['impressionsCount']);
                        $summarizedArray[$key]['cost'] = $summarizedArray[$key]['cost'] + $creativeValue['cost'];
                        $summarizedArray[$key]['is_free'] = 0;

                    } else {

                        if (!isset($summarizedArray[$key]['free_impresions'])) $summarizedArray[$key]['free_impresions'] = 0;
                        $summarizedArray[$key]['free_impresions'] = $summarizedArray[$key]['free_impresions'] + str_replace('.', '', $creativeValue['impressionsCount']);
                        $summarizedArray[$key]['summarised_is_free'] = 1;

                    }

                    $summarizedArray[$key]['network'] = $kernel->getContainer()->get('InsertionOrdersModel')->getNetworkName($creativeValue['network']);

                    $summarizedArray[$key]['index'] = $creativeKey;

                }

                //print_r($summarizedArray);
                $adFormats = 0;
                $network = 'Gameloft';
                $countries = [];
                $startDate = [];
                $endDate = [];

                foreach ($summarizedArray as $value) {
                    $adFormats++;
                    $countries = array_merge($countries, $value['country_id']);
                    $startDate[] = $value['start_date'];
                    $endDate[] = $value['end_date'];
                }

                //print_r($summarizedArray);

                foreach ($summarizedArray as $value) {

                    if (count($summarizedArray) == 1) $value['index'] = 0;

                    if ($value['network'] != 'Gameloft') $network = 'Gameloft & Friends';

                    $tempColSpan = 1;

                    if ($value['free_impresions'] > 1) {
                        $tempColSpan = 0;
                        $adFormats = 0;
                    }

                    $html .= '
                    <tr >
                        ' . ($value['index'] == 0 ? '<td align="center" valign="center" rowspan="' . $adFormats . '">' . $network . '</td> ' : '') . '
                        <td align="center" valign="center">' . $kernel->getContainer()->get('InsertionOrdersModel')->getAdFormatName($value['creative_type_id']) . '</td>
                        ' . ($value['index'] == 0 ? '<td align="center" valign="center" rowspan="' . $adFormats . '">All Available Games</td>' : '') . '
                        ' . ($value['index'] == 0 ? '<td align="center" valign="center" rowspan="' . $adFormats . '">' . (isset($value['platform_id']) ? $value['platform_id'] : "") . '</td>' : '') . '
                        ' . ($value['index'] == 0 ? '<td align="center" valign="center"  rowspan="' . $adFormats . '">' . implode(', ', array_unique($countries)) . '</td>' : '') . '
                        ' . ($value['index'] == 0 ? '<td align="center" valign="center"  rowspan="' . $adFormats . '">' . min($startDate) . '</td>' : '') . '
                        ' . ($value['index'] == 0 ? '<td align="center" valign="center"  rowspan="' . $adFormats . '">' . max($endDate) . '</td>' : '') . '
                        <td align="center" valign="center">' . $value['pricing_model'] . '</td>
                        <td align="center" valign="center" rowspan="' . $tempColSpan . '">

                           <div style="padding: 10px;">
                                ' . number_format($value['impresions']) . '                            
                            </div>
                            ' . ($value['summarised_is_free'] > 0 && $value['free_impresions'] > 1 ? '
                            <hr style="border: 1px solid #dbdbdb;" />
                            <div style="padding: 10px;position:relative">
                            
                                <span>' . $value['free_impresions'] . ' ' . ($value['free_impresions'] > 0 ? '<span> - Free Ad</span>' : '') . '</span>
                            </div>
                            ' : '')
                        . '</td>
                        <td align="center" valign="center">' . number_format($value['price'], 2, ".", ",") . ' ' . (isset($dio_data["currencyName"]) ? $dio_data["currencyName"] : "") . '</td>
                        
                        <td align="center" valign="center" rowspan="' . $tempColSpan . '">
                           <div style="padding: 10px;">
                                ' . number_format($value['cost'], 2, ".", ",") . ' ' . (isset($dio_data["currencyName"]) ? $dio_data["currencyName"] : "") . '         
                                               
                            </div>
                            ' . ($value['summarised_is_free'] > 0 && $value['free_impresions'] > 1 ? '
                            <hr style="border: 1px solid #dbdbdb;" />
                            <div style="padding: 10px;position:relative">
                           
                                <span>0.00  ' . (isset($dio_data["currencyName"]) ? $dio_data["currencyName"] : "") . ($value['free_impresions'] > 0 ? '<span> - Free Ad</span>' : '') . '</span>
                            </div>
                            ' : '')
                        . '</td>
                   </tr>
                     ';
                }

            } else {

                foreach ($dio_data['creativeTypes'] as $creativeKey => $creativeValue) {

                    if (!is_array($creativeValue['platform_id'])) $creativeValue['platform_id'] = explode('-', $creativeValue['platform_id']);

                    $platformsNames = [];

                    foreach ($creativeValue['platform_id'] as $k => $tempPlf) {
                        if (empty($tempPlf)) break;
                        if ($tempPlf == 9) {
                            $platformsNames = 'All Platforms';
                            break;
                        } else if ($tempPlf == 10) {
                            $platformsNames = 'Not Applicable';
                            break;
                        } else {
                            $platformsNames[] = $kernel->getContainer()->get('InsertionOrdersModel')->getPlatformName($tempPlf);
                            break;
                        }
                    };
                    if (is_array($platformsNames))
                        $platformsNames = implode(',', $platformsNames);
                    //$platformsNames = rtrim($platformsNames,',');

                    if (!is_array($creativeValue['country_id'])) $creativeValue['country_id'] = explode(',', $creativeValue['country_id']);
                    $countryNames = [];
                    foreach ($creativeValue['country_id'] as $k => $tempCountry) {
                        if (empty($tempCountry)) break;
                        $countryNames[] = $kernel->getContainer()->get('CountriesModel')->get_country_name($tempCountry);
                    }
                    $countryNames = array_filter($countryNames);
                    $countryNames = implode(', ', $countryNames);

                    if (!is_array($creativeValue['games_id']))
                        $creativeValue['games_id'] = explode(',', $creativeValue['games_id']);
                    $gameNames = [];

                    foreach ($creativeValue['games_id'] as $k => $tempGame) {
                        if (empty($tempGame)) break;
                        if ($tempGame == 1) {
                            $gameNames[] = 'Gameloft Games';
                        } else if ($tempGame == 2) {
                            $gameNames[] = 'All Games';
                        } else {
                            $gameNames[] = $kernel->getContainer()->get('GamesModel')->get_name_by_id($tempGame);
                        }
                    }

                    $gameNames = array_filter($gameNames);

                    $gameNames = implode(', ', $gameNames);

                    switch ($creativeValue['pricing_model_id']) {
                        case '10':
                            $pricingModel = 'Package';
                            break;
                        case '11':
                            $pricingModel = 'Fee';
                            break;
                        default:
                            $pricingModel = $kernel->getContainer()->get('InsertionOrdersModel')->get_pricing_models_name($creativeValue['pricing_model_id']);
                    }

                    $html .= '
                    <tr >
                        <td style="text-align: center;">' . $kernel->getContainer()->get('InsertionOrdersModel')->getNetworkName($creativeValue['network']) . '</td>
                        <td style="text-align: center;">' . $kernel->getContainer()->get('InsertionOrdersModel')->getAdFormatName($creativeValue['creative_type_id']) . '</td>
                        <td style="text-align: center;">' . $gameNames . '</td>
                        <td style="text-align: center;">' . $platformsNames . '</td>
                        <td style="text-align: center;">' . $countryNames . '</td>
                        <td style="text-align: center;">' . $creativeValue['start_date'] . '</td>
                        <td style="text-align: center;">' . $creativeValue['end_date'] . '</td>
                        <td style="text-align: center;">' . $pricingModel . '</td>
                        <td style="text-align: center;">' . $creativeValue['impressions_count'] . '</td>
                        <td style="text-align: center;">' . number_format($creativeValue['price'], 2, ".", ",") . ' ' . (isset($dio_data["currencyName"]) ? $dio_data["currencyName"] : "") . '</td>
                        <td style="text-align: center;">' . number_format($creativeValue['cost'], 2, ".", ",") . ' ' . (isset($dio_data["currencyName"]) ? $dio_data["currencyName"] : "") . '</td>
                    </tr>
                 ';
                }

            };
        }

        //print_r($html);

        $client_rebate_percentage = 0;
        if ($dio_data['client_rebate'] > 0) {
            $client_rebate_percentage = ($dio_data['client_rebate'] * 100) / $dio_data['total_cost'];
        }

        $html .= '
    </tbody>
</table>

<table style="padding-top:30px;">
    <tr>
        <td></td>
        
        <td>
            <table>
                <tr>
                    <td><span style="float: left;margin-right: 20px; font-weight: bold">Total Cost:</span></td>
                    <td> ' . number_format($dio_data['total_cost'], 2, '.', ',') . ' ' . (isset($dio_data["currencyName"]) ? $dio_data["currencyName"] : "") . '</td>
                </tr>

                <tr>
                    <td><span style="float: left;margin-right: 20px; font-weight: bold">Total Net Cost:</span></td>
                    <td> ' . number_format($dio_data['total_net_cost'], 2, '.', ',') . ' ' . (isset($dio_data["currencyName"]) ? $dio_data["currencyName"] : "") . '</td>
                </tr>
';


        if ($dio_data['client_rebate'] > 0) {
            $html .= '
                <tr>
                    <td><span style="float: left;margin-right: 20px; font-weight: bold">Client Rebate:</span></td>
                    <td> ' . number_format($dio_data['client_rebate'], 2, '.', ',') . ' ' . (isset($dio_data["currencyName"]) ? $dio_data["currencyName"] : "") . '</td>
                </tr>
                <tr>
                    <td><span style="float: left;margin-right: 20px; font-weight: bold">Client Rebate Percentage:</span></td>
                    <td> ' . number_format($client_rebate_percentage, 2, '.', ',') . ' %</td>
                </tr>
';
        }

        $html .= '  </table>
        </td>
    </tr>
</table>
';


// reset pointer to the last page
        //$pdf->lastPage();

// output the HTML content
        $pdf->writeHTML($html, false, false, true, false, '');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

// test custom bullet points for list

        $html = '
<div class="insertion_order_terms" style="page-break-before: always; text-align: justify">
    <table style="font-size: 12px;">
        <tr >
            <td style="width:130px">
                <img src="' . $GLlogo . '" style="width:100px;position:relative;"/>
            </td>
            <td style="line-height: 50px;float:left; font-weight: bold; font-size: 12px;width: 330px;">Gameloft Insertion Order ' . $dio_data['deal_number_label'] . '</td>
        </tr>
    </table>
    <table style="font-size: 9px;" class="insertion_order_terms">
        <tr style="padding-bottom: 10px;">
            <th class="tg-9hbo" style="width:80px; font-weight: bold; padding-bottom: 10px;">Partner</th>
            <th class="tg-yw4l" style="width:450px!important; padding-bottom: 10px;">The signatory entity of this Insertion Order i.e. the Advertiser or the
                Advertising Agency as the case may be.
            </th>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold">Billing counts</td>
            <td class="tg-yw4l">based on Gameloft Reporting</td>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Insertion Order Terms</td>
            <td class="tg-yw4l">The Parties may extend the Term of this Insertion Order by mutual written
                agreement (e-mail accepted).
            </td>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Native Game Advertising</td>
            <td class="tg-yw4l">the following ad formats : Sponsored Event (TLE), Branded Booster, In-Game Brand & Product Placement</td>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Invoice / Payment Terms</td>
            <td class="tg-yw4l">
                <span><u>For all campaigns (excluding In-Game Brand & Product Placement):</u> <br /></span>
                Invoicing and payments will be based on Gameloft’s figures, unless otherwise specified in
                the Additional terms. If there is a discrepancy of more than 10% between Gameloft and (3rd
                Party Partner)’s figures, the parties shall discuss in good faith to resolve this issue.
                The initial invoice will be sent to Partner by Gameloft upon execution of the IO.
                If the IO hasn\'t delivered within the initial Calendar month of running, Gameloft will
                invoice Partner
                for the services provided on a calendar-month basis with the net cost based on actual
                delivery.
                Partner will provide payment of full invoice within <strong>' . (isset($dio_data["invoice_term"]) ? $dio_data["invoice_term"] : 0) . '</strong> days of the
                invoice date.
                <span> <br />
                    <u>For In-Game Brand & Product Placement campaigns:</u><br />
                    The payment of the price of the campaign shall be in two times as follows:<br />
                    - Production phase: Partner shall pay 30% of the price of the campaign, upon receipt and
                    approval of the Native Advertising formats by Advertiser and/or Partner.<br />
                    - Campaign phase: Partner shall pay the remaining 70% of the price of the campaign at the launch date of the campaign.
                </span>
            </td>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Cancellation &amp; Termination</td>
            <td class="tg-yw4l">
                <span><u>For all campaigns (excluding Native Game Advertising):</u><br/></span>
                Early cancellation of the campaign by Partner (with or without cause) within fourteen (14)
                calendar days before the release of the campaign, or during the campaign, may be subject to
                penalty fees equal to the costs for the seventh (7th) first days of the campaign.
                Partner will remain liable to Gameloft for amounts due for any custom content or development
                of rich media format and Native Advertising (“Custom Material”) completed by Gameloft prior
                to the effective date of termination.
                For IOs that contemplate the provision or creation of Custom Material, Gameloft will specify
                the amounts due for such Custom Material and Partner will pay for such Custom Material within
                fifteen (15) calendar days from receiving an invoice therefore.
                <span><br />
                    <u>For Native Game Advertising campaigns:</u> <br/>
                    The cancellation of the campaign by Partner (with or without cause) within before the release of the campaign,
                    or during the campaign, may be subject to penalty fees equal to thirty per cent (30%)
                    of the price of the campaign.
                </span>
            </td>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Creative</td>
            <td class="tg-yw4l">Advertising materials, in accordance with Gameloft\'s creative policies,
                should be received 3 business days before the start date of the campaign. Any delay will
                delay the campaign.
            </td>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Reporting</td>
            <td class="tg-yw4l">
                <span><u>For all campaigns (excluding Native Game Advertising):</u> <br/></span>
                Reporting Reports (excel) will mention impressions, spend, broken out per day, and
                summarized by ad format.
                <span><br />
                    <u>For Native Game Advertising campaigns:</u><br/>
                    Reporting Reports (excel) will mention users reach, activity and
                    participation in the defined host game.
                </span>
            </td>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Personal Information</td>
            <td class="tg-yw4l">Neither Advertiser nor Advertising Agency shall collect and exploit
                "Personal Information"
                (as defined by COPPA and GDPR) from users of child-directed games or users who have been
                identified or have identified
                themselves as being under 13 in accordance with COPPA regulations or 16 in accordance with GDPR.
            </td>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Authorization</td>
            <td class="tg-yw4l">Advertiser and Advertiser Agency
                authorize Gameloft to use and publicly display its logo,
                trade names, trade/service marks and creative, in whole or in part, on Gameloft website or
                Gameloft
                marketing or corporate materials for promotion and business prospection. Should any
                conditions or
                restrictions apply please advise: <br/>
                (i) Use of Advertiser and Advertiser Agency logos, trade name or trade/service marks: <br/>
                (ii) Use of campaign creatives: <br/>
            </td>

        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Additional Terms</td>
            <td class="tg-yw4l">' . $dio_data['additional_terms'] . '</td>
        </tr>
        <tr>
            <td class="tg-9hbo" style="font-weight: bold; text-align: left">Gameloft General Terms and Conditions</td>
            <td class="tg-yw4l">http://mkt-web.gameloft.com/static/glads-cms/Gameloft_Mobile_Advertising_Terms__Conditions.pdf</td>
        </tr>
        <tr>
            <td class="tg-9hbo"></td>
            <td class="tg-yw4l">By signing below, Partner hereby represents that it has reviewed and
                understood, and agrees to be bound by, this Insertion Order and Gameloft’s General Terms &
                Conditions, attached to this Insertion Order.
            </td>
        </tr>
    </table>
</div>
';

        $html .= '
        <table style="margin-top: 50px; font-size: 10px;">
            <tr>
                <td style="text-align: center;">
                        <span style="font-weight: bold">Authorized Gameloft Signature</span><br>
                            ' . (isset($dio_data["adv_sales_name"]) ? $dio_data["adv_sales_name"] : 0) . '<br>
                            ' . (isset($dio_data["adv_sales_title"]) ? $dio_data["adv_sales_title"] : 0) . '        
                 </td>
                <td  style="text-align: center;">
                <span style="font-weight: bold">Authorized Client Signature</span><br>
                            ' . (isset($dio_data["adv_client_name"]) ? $dio_data["adv_client_name"] : 0) . '<br>
                            ' . (isset($dio_data["adv_client_title"]) ? $dio_data["adv_client_title"] : 0) . '
                
                </td>
            </tr>    
        </table>
        
        ';


// output the HTML content
        $pdf->writeHTML($html, true, false, true, false, '');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

// reset pointer to the last page
        $pdf->lastPage();

// ---------------------------------------------------------

        //Close and output PDF document
        //$pdf->Output('example_006.pdf', 'I');
        //ob_end_clean();
        $filename = "Insertion_order_" . $dio_data['deal_number_label'] . "-" . date('Y-m-d') . "-" . date('H-m-s') . ".pdf";

        if (isset($dio_data['save_type']) && $dio_data['save_type'] == 2) {
            $filename = "Insertion_order_" . $dio_data['deal_number_label'] . "-Sales-Force2";
            if ($type == 2) {
                $filename = $filename . "-summarized";
            }
        } else {
            $filename = "Insertion_order_" . $dio_data['deal_number_label'] . "-Sales-Force1";
            if ($type == 2) {
                $filename = $filename . "-summarized";
            }
        }

        $filename = $filename . '.pdf';

        $dioYear = explode('-', $dio_data['deal_number_label']);

        $filelocation = $kernel->getContainer()->getParameter('dioPdfUploadPath');

        $this->makeDir($filelocation . $dioYear[0]);

        $fileNL = $filelocation . $dioYear[0] . '/' . $filename; //Linux

        $checkFilePatch = $kernel->getContainer()->get('InsertionOrdersModel')->check_signed_dio($fileNL, $dio_data['id']);

        if (empty($checkFilePatch)) {
            $signedDioId = $kernel->getContainer()->get('InsertionOrdersModel')->put_signed_dio(
                array(
                    'insertion_order_id' => $dio_data['id'],
                    'path' => $fileNL
                )
            );
        } else {
            $signedDioId = $checkFilePatch->id;
        }

        if ($type == 2) {
            $pdf->Output($fileNL, 'F');
            return json_encode(array(
                'success' => true,
                'message' => 'Summarized PDF export has been generated!',
                'id' => $signedDioId,
                'dio_id' => $dio_data['id']
            ));
        } else {
            $pdf->Output($fileNL, 'F');
        }

//============================================================+
// END OF FILE
//============================================================+

    }

    public function check_advertised_company($advertised_company)
    {
        global $kernel;
        //Advertised company check / insert
        $advertised_company_id = $kernel->getContainer()->get('AdvertisedCompanyModel')->get_id_by_name($advertised_company);
        if (isset($advertised_company_id) && ($advertised_company_id == null || $advertised_company_id == '')) {
            $advertised_company_data = array(
                'name' => $advertised_company,
                'is_deleted' => 0
            );
            $advertised_company_id = $kernel->getContainer()->get('AdvertisedCompanyModel')->insert($advertised_company_data);
        }
        return $advertised_company_id;
        ////////////////////////////////////
    }

    public function check_advertised_brand($advertising_brand)
    {
        global $kernel;

        //Advertised brand check / insert
        $advertising_brand_id = $kernel->getContainer()->get('AdvertisedBrandsModel')->get_id_by_name($advertising_brand);

        if (isset($data['debug']) == 'test2') {
            print_r($advertising_brand_id);
            die;
        }

        if (isset($advertising_brand_id) && ($advertising_brand_id == null || $advertising_brand_id == '')) {
            $advertised_brand_data = array(
                'name' => $advertising_brand,
                'is_deleted' => 0
            );
            $advertising_brand_id = $kernel->getContainer()->get('AdvertisedBrandsModel')->insert($advertised_brand_data);
        }
        return $advertising_brand_id;
        //////////////////////////////////
    }

    public function get_industry()
    {
        global $kernel;

        $industry = [];

        $advertised_brands_industry_sectors = $kernel->getContainer()->get('AdvertisedBrandsModel')->get_advertised_brands_industry_sectors();
        $advertised_brands_industry_sector_categories = $kernel->getContainer()->get('AdvertisedBrandsModel')->get_advertised_brands_industry_sector_categories();

        foreach ($advertised_brands_industry_sectors as $key => $value) {
            foreach ($advertised_brands_industry_sector_categories as $k => $v) {

                if (!isset($industry[$value->id])) $industry[$value->id] = [];
                if (!isset($industry[$value->id]['categories'])) $industry[$value->id]['categories'] = [];

                $industry[$value->id]['name'] = $value->name;
                $industry[$value->id]['id'] = $value->id;

                if ($value->id == $v->adv_industry_id) {
                    $industry[$value->id]['categories'][] = [
                        'id' => $v->id,
                        'name' => $v->name,
                    ];
                }


            }
        }

        $industry = array_values($industry);

        return json_encode($industry);

    }

    public function check_client($client)
    {
        global $kernel;
        //Client check / insert
        $client_id = $kernel->getContainer()->get('ClientsModel')->get_id_by_name($client);
        if (isset($client_id) && ($client_id == null || $client_id == "")) {
            $client_data = array(
                'name' => $client,
                'is_deleted' => 0
            );
            $client_id = $kernel->getContainer()->get('ClientsModel')->insert($client_data);
        }
        ///////////////////////
        return $client_id;
        //////////////////////////////////
    }

    public function check_client_contact($contact, $client)
    {
        global $kernel;
        //Client check / insert
        $contact_id = $kernel->getContainer()->get('ClientsModel')->get_contact_id_by_name($contact, $client);
        if (isset($contact_id) && ($contact_id == null || $contact_id == "")) {
            $contact_id = 0;
        }
        ///////////////////////
        return $contact_id;
        //////////////////////////////////
    }

    public function invoicing_entity_check($value)
    {
        global $kernel;
        $data = [];
        if (strlen($value) == 3) {
            $data['invoicing_entity_id'] = $kernel->getContainer()->get('InvoicingEntitiesModel')->getIEbyIO($value);
            if (empty($data['invoicing_entity_id'])) {
                return json_encode(array(
                    'success' => false,
                    'message' => 'Please provide a valid invoicing entity!'
                ));
            }
        } else {
            $data_invoicing_entity_id = $kernel->getContainer()->get('InvoicingEntitiesModel')->get_id_by_email($value)[0];
            if (empty($data_invoicing_entity_id)) {
                return json_encode(array(
                    'success' => false,
                    'message' => 'Please provide a valid invoicing entity email address!'
                ));
            }
            $data['invoicing_entity_id'] = $data_invoicing_entity_id->id;
            $data['invoicing_entity'] = $data_invoicing_entity_id->invoicing_entity_io;
        }
        return $data;
    }

    public function sort_response_numeric($value, $array)
    {
        $return_values = [];
        $conversion = 0;

        if ($value == null) return '';

        foreach ($value as $dioValues) {
            foreach ($array as $arrayValues) {
                if ($dioValues == $arrayValues['name']) {
                    $return_values[] = $arrayValues['id'];
                    $conversion = 1;
                } else if ($dioValues == $arrayValues['id']) {
                    $return_values[] = $arrayValues['name'];
                    $conversion = 2;
                }
            }
        }

//        if($conversion == 1) {
//            $return_values = implode('-', $return_values);
//        } else {
//            $return_values = implode(', ', $return_values);
//        }
        return $return_values;
    }

    public function set_dio_from_api($post)
    {

        $dioDates = array(
            'dio_start_date' => '0000-00-00',
            'dio_end_date' => '0000-00-00',
        );

        $unsetData = ['client_rebate',
            'billing_contact_id',
            'business_contact_name',
            'save_type',
            'company_purchase_order',
            'mediaGroupAgency',
            'additional_terms',
            'related_party',
            'invoice_term',
            'dio_status',
            'advertised_brands_industry_sector_category_id',
            'advertised_brands_industry_sector_id',
            'business_contact_id',
            'eur_total_net_cost',
            'age_targeting_from',
            'age_targeting_to',
            'modification',
            'total_net_cost',
            'amount',
            'total_cost',
            'total_net_cost',
            //client

        ];

        $data = array_merge($post, $dioDates);

        foreach ($unsetData as $value) {
            if (!isset($data[$value])) {
                $data[$value] = 0;
                if ($value == 'save_type') {
                    $data[$value] = 1;
                }
            }
        }

        $data['amount'] = $data['total_net_cost'];

        //print_r($data);

        global $kernel;

        //Data that is available
        $data['deal_number'] = $kernel->getContainer()->get('BusinessDealNumbersModel')->getLastNumber() + 1;

        $data['user_id'] = $kernel->getContainer()->get('UsersModel')->get_user_id_by_email($data['user_email']);

        if (empty($data['user_id']) || $data['user_id'] == '' || $data['user_id'] == 0) {
            return json_encode(array(
                'success' => false,
                'message' => 'User does not exist!'
            ));
        }

        $invoicing_entity = $this->invoicing_entity_check($data['invoicing_entity']);

        $data['invoicing_entity_id'] = $invoicing_entity['invoicing_entity_id'];

        $data['invoicing_entity'] = $invoicing_entity['invoicing_entity'];

        $currency_data = $kernel->getContainer()->get('CurrenciesModel')->get_details_by_iso($data['currency']);

        $data['currency'] = isset($currency_data['id']) ? $currency_data['id'] : 0;

        $data['deal_number_label'] = explode('-', $data['io_signature_date'])[0] . '-' . $data['invoicing_entity'] . '-' . $data['deal_number'];

        $data['advertised_company_id'] = self::check_advertised_company($data['advertised_company']);

        $data['advertising_brand_id'] = self::check_advertised_brand($data['advertising_brand']);

        $data['client_id'] = self::check_client($data['client_name']);

        $data['advertised_brands_industry_sector_category_id'] = $this->non_numeric_check('advertised_brands_industry_sector_category_id', $data['advertised_brands_industry_sector_category_id']);

        $data['advertised_brands_industry_sector_id'] = $this->non_numeric_check('advertised_brands_industry_sector_id', $data['advertised_brands_industry_sector_id']);

        //media_group=2&media_agency=708
        if (isset($data['media_group']) && !empty($data['media_group']) && !is_null($data['media_group'])) {
            $mediaGroup = $this->non_numeric_check('media_group', $data['media_group']);
            $data['mediaGroupAgency'] = $mediaGroup . ',0';
            if (isset($data['media_agency']) && !empty($data['media_agency'])) {
                $mediaAgency = $this->non_numeric_check('media_agency', $data['media_agency'], $mediaGroup);
                $data['mediaGroupAgency'] = $mediaGroup . ',' . $mediaAgency;
            }
            unset($data['media_group'], $data['media_agency']);
        } else {
            $data['mediaGroupAgency'] = '17,0';
        }

//        //country check / id
        if (isset($data['country']) && $data['country'] != '') {
            $data['country'] = $this->non_numeric_check('country', $data['country']);
        }

        if (isset($data['country_id']) && $data['country_id'] != '') {
            $data['country_id'] = $this->non_numeric_check('country_id', $data['country_id']);
        }

        if (isset($data['io_campaign_managers']) && $data['io_campaign_managers'] != '') {
            $data['io_campaign_managers'][0] = $this->non_numeric_check('io_campaign_managers', $data['io_campaign_managers'][0]);
        }

        if (isset($data['insertion_orders_contacts']) && $data['insertion_orders_contacts'] != '') {
            $data['insertion_orders_contacts'] = $this->non_numeric_check('insertion_orders_contacts', $data['insertion_orders_contacts']);
        }

//        ///////////////////////

        if (isset($data['business_contact_name'])) {
            $data['billing_contact_id'] = self::check_client_contact($data['business_contact_name'], $data['client_id']);
        }

        if (isset($data['test'])) {
            print_r($data['creativeTypes']);
            die;
        }

        if (isset($data['creativeTypes'])) {

            if (!is_array($data['creativeTypes']))
                $data['creativeTypes'] = json_decode($data['creativeTypes']);

            $tempArray = [];

            if (isset($data['test'])) {
                print_r($data['creativeTypes']);
                die;
            }

            foreach ($data['creativeTypes'] as $key => $value) {

                $tempArray[$key]['creative_type_id'] = $this->non_numeric_check('creative_type_id', $data['creativeTypes'][$key]['creative_type_id']);
                //$tempArray[$key]['creative_type_name'] = $data['creativeTypes'][$key]->creative_type_name;
                $tempArray[$key]['platform_id'] = $this->non_numeric_check('platform_id', $data['creativeTypes'][$key]['platform_id']);
                $tempArray[$key]['country_id'] = $this->non_numeric_check('country_id', $data['creativeTypes'][$key]['country_id']);
                $tempArray[$key]['start_date'] = $data['creativeTypes'][$key]['start_date'];
                $tempArray[$key]['end_date'] = $data['creativeTypes'][$key]['end_date'];
                $tempArray[$key]['pricing_model_id'] = $this->non_numeric_check('pricing_model_id', $data['creativeTypes'][$key]['pricing_model_id']);
                $tempArray[$key]['impressions_count'] = $data['creativeTypes'][$key]['impressions_count'];
                $tempArray[$key]['price'] = $data['creativeTypes'][$key]['price'];
                $tempArray[$key]['cost'] = $data['creativeTypes'][$key]['cost'];
                $tempArray[$key]['prod_date'] = $data['creativeTypes'][$key]['prod_date'];
                $tempArray[$key]['net_unit_price'] = $data['creativeTypes'][$key]['net_unit_price'];
                //$tempArray[$key]['cmp_end_date'] = $data['creativeTypes'][$key]['cmp_end_date'];
                //$tempArray[$key]['cmp_start_date'] = $data['creativeTypes'][$key]['cmp_start_date'];
                $tempArray[$key]['net_cost'] = $data['creativeTypes'][$key]['net_cost'];
                $tempArray[$key]['is_free'] = $data['creativeTypes'][$key]['is_free'];
                $tempArray[$key]['salesforce_opp_id'] = $data['creativeTypes'][$key]['salesforce_opp_id'];
                $tempArray[$key]['network'] = $this->non_numeric_check('network', $data['creativeTypes'][$key]['network']);
                $tempArray[$key]['network_name'] = $this->non_numeric_check('network', $data['creativeTypes'][$key]['network_name']);
                $tempArray[$key]['games_id'] = $this->non_numeric_check('games_id', $data['creativeTypes'][$key]['games_id']);

                if (isset($tempArray[$key]['creative_type_id']) && $tempArray[$key]['creative_type_id'] != 25) {
                    $tempArray[$key]['prod_date'] = '0000-00-00';
                }

            }

            $data['creativeTypes'] = $tempArray;

        }

        $_POST = $data;

        if (isset($data['test']) && $data['test'] == 'test') {
            print_r($data);
            die;
        }

        $dio_data = $this->put_addAction();

        $dio_data = $dio_data->getContent();

        $dio_timestamp = "";

        $dio_data = json_decode($dio_data);

        $_POST = $data;

        $_POST['id'] = $dio_data->id;

        $pdfSave = self::get_listAction($_POST['id'], 'api_pdf_save');

        self::generate_dio_pdf($pdfSave);

        $dio_timestamp = $kernel->getContainer()->get('InsertionOrdersModel')->get_last_modification($dio_data->id);

        return json_encode(array(
            'success' => true,
            'deal_number' => $data['deal_number_label'],
            'message' => 'DIO ' . $data['deal_number_label'] . ' was added',
            'dio_id' => $dio_data->id,
            //'dio_link' => 'https://acq.gameloft.org/adserver/web/insertion-orders/get_edit/' . $dio_data->id . '/'
            'dio_link' => 'https://acq.gameloft.org/adserver/web/insertion-orders/get_edit/' . $dio_data->id . '/',
            'dio_timestamp' => $dio_timestamp
        ));

    }

    public function update_dio_api($data)
    {

        global $kernel;

        $insertionOrderId = $data['dio_id'];

        $updateDioFromApi = [];

        $updateDioFromApi['dioQuery'] = '';

        $updateDioFromApi['dioCreativeQuery'] = '';

        if (!empty($data)) {

            $dioData = self::get_listAction($data['dio_id'], 'api_pdf_save');

            if (isset($dioData) && isset($dioData[0])) {
                $dioData = $dioData[0];
                $eurExchangeRate = $kernel->getContainer()->get('InsertionOrdersModel')->getExchangeRates($dioData['currency'], 'EUR', $dioData['io_signature_date'], 1);
            } else {
                $eurExchangeRate = 1;
            }

            if (isset($data['authorized_sales_name'])) {
                $data['adv_sales_name'] = $data['authorized_sales_name'];

            }
            if (isset($data['authorized_sales_title'])) {
                $data['adv_sales_title'] = $data['authorized_sales_title'];

            }
            if (isset($data['authorized_client_name'])) {
                $data['adv_client_name'] = $data['authorized_client_name'];

            }

            if (!empty($data['authorized_client_title'])) {
                $data['adv_client_title'] = $data['authorized_client_title'];
            }

            if (!empty($data['total_net_cost'])) {
                $data['amount'] = $data['total_net_cost'];
                $data['eur_total_net_cost'] = $eurExchangeRate * $data['total_net_cost'];
            }

            $_POST = $data;

            if (isset($data['user_email'])) {
                $user_id = $kernel->getContainer()->get('UsersModel')->get_user_id_by_email($data['user_email']);
                $data['user_id'] = $user_id;
            }

            if (isset($data['dio_id'])) {
                $data['id'] = $data['dio_id'];
            }

            if (isset($data['advertised_company'])) {
                $data['advertised_company_id'] = self::check_advertised_company($data['advertised_company']);
            }

            if (isset($data['advertising_brand'])) {
                $data['advertising_brand_id'] = self::check_advertised_brand($data['advertising_brand']);
            }

            if (isset($data['client_name'])) {
                $data['client_id'] = self::check_client($data['client_name']);
                $_POST['client_id'] = $data['client_id'];
            }

            if (isset($data['invoicing_entity'])) {
                $data['invoicing_entity_id'] = $data['invoicing_entity'];
            }

            if (isset($data['currency'])) {
                $currency_data = $kernel->getContainer()->get('CurrenciesModel')->get_details_by_iso($data['currency']);
                $data['currency'] = isset($currency_data['id']) ? $currency_data['id'] : 0;
            }

            $kernel->getContainer()->get('InsertionOrdersModel')->insertion_orders_contacts($data['id'], $data);


            if (isset($data['business_contact_id'])) {
                $_POST['billing_contact_id'] = $data['business_contact_id'];
                $data['billing_contact_id'] = $data['business_contact_id'];
            }

            if (isset($data['business_contact_name'])) {
                $data['billing_contact_id'] = self::check_client_contact($data['business_contact_name'], $data['client_id']);
                $_POST['business_contact_id'] = $data['billing_contact_id'];
            }

            if (isset($data['creativeTypes'])) {

                if (!is_array($data['creativeTypes']))
                    $data['creativeTypes'] = json_decode($data['creativeTypes'], true);

                if (isset($data['debug']) == 'test') {
                    print_r($data);
                }

                $this->dio_changes_notification($data);

                $dioData = self::get_listAction($data['id'], 'api_pdf_save');

                $eurExchangeRate = 1;

                if (isset($dioData) && isset($dioData[0])) {
                    $dioData = $dioData[0];
                    $eurExchangeRate = $kernel->getContainer()->get('InsertionOrdersModel')->getExchangeRates($dioData['currency'], 'EUR', $dioData['io_signature_date'], 1);
                }

                $tblOldState = $kernel->getContainer()->get('HistoryInsertionOrdersModel')->getCurrentState($insertionOrderId);

                foreach ($data['creativeTypes'] as $k => $v) {
                    if (isset($v['salesforce_opp_id']) && ($v['salesforce_opp_id'] != 0 || $v['salesforce_opp_id'] != '')) {
                        if (isset($v['salesforce_opp_id'])) {
                            $dioData = $kernel->getContainer()->get('InsertionOrdersModel')->get_ad_format_data_salesforce($v['salesforce_opp_id']);
                            if(isset($dioData[0]->salesforce_opp_id)) {
                                $data['creativeTypes'][$k]['cmp_start_date'] = $dioData[0]->cmp_start_date;
                                $data['creativeTypes'][$k]['cmp_end_date'] = $dioData[0]->cmp_end_date;
                            }
                        }
                    }
                }

                $kernel->getContainer()->get('InsertionOrdersModel')->delete_dio_creative_type($insertionOrderId);

                $total_net_cost = 0;

                foreach ($data['creativeTypes'] as $k => $v) {
                    $v['eurExchangeRate'] = $eurExchangeRate;
                    if (isset($v['is_free']) && $v['is_free'] == 0) {
                        $total_net_cost += $v['net_cost'];
                    }

                    if (isset($v['salesforce_opp_id']) && ($v['salesforce_opp_id'] != 0 || $v['salesforce_opp_id'] != '')) {
                        if (isset($v['salesforce_opp_id'])) {
                            $kernel->getContainer()->get('InsertionOrdersModel')->update_dio_creative_type_salesforce($insertionOrderId, $v['salesforce_opp_id'], $v);
                        }
                    } else {
                        $kernel->getContainer()->get('InsertionOrdersModel')->update_dio_creative_type($insertionOrderId, $v);
                    }

                }

                $data['total_net_cost'] = $total_net_cost;

                $data['eur_total_net_cost'] = $total_net_cost * $eurExchangeRate;

                $kernel->getContainer()->get('HistoryInsertionOrdersModel')->change($insertionOrderId, $tblOldState);

            }

            if (isset($data['media_group']) && !is_null($data['media_group']) && !is_array($data['media_group'])) {
                if (isset($data['media_agency']) && !is_array($data['media_agency'])) {
                    $data['mediaGroupsAgency'] = $data['media_group'] . ',' . $data['media_agency'];
                } else {
                    $data['mediaGroupsAgency'] = $data['media_group'];
                }
                unset($data['media_group'], $data['media_agency']);
            } else {
                unset($data['media_group'], $data['media_agency']);
                $data['mediaGroupsAgency'] = '17,0';
            }

            if ((isset($data['client_id']) && !empty($data['client_id']))) {

                if (!isset($_POST['business_contact_id'])) {
                    $_POST['business_contact_id'] = 0;
                }

                //$clientData = ClientsController::put_editAction(1);
                $clientController = new ClientsController;
                $clientData = $clientController->put_editAction();
                $clientData = json_encode($clientData, true);

                $clientData = json_decode($clientData, true);

            }

            unset($data['authorized_sales_name'],
                $data['authorized_sales_title'],
                $data['authorized_client_name'],
                $data['authorized_client_title'],
                $data['user_email'],
                $data['dio_id'],
                $data['advertised_company'],
                $data['advertising_brand'],
                $data['advertised_brand'],
                $data['client_name'],
                $data['invoicing_entity'],
                $data['insertion_orders_contacts'],
                $data['io_campaign_managers'],
                $data['business_contact_id'],
                $data['creativeTypes'],
                $data['billing_address'],
                $data['accounting_email_address'],
                $data['accounting_phone_number'],
                $data['accounting_contact'],
                $data['email_address'],
                $data['city'],
                $data['country'],
                $data['phone_number'],
                $data['vat_number'],
                $data['zip_code'],
                $data['state'],
                $data['business_contact_name'],
                $data['workday_id']
            );

            $tblOldState = $kernel->getContainer()->get('HistoryInsertionOrdersModel')->getCurrentState($insertionOrderId);

            $update = $kernel->getContainer()->get('InsertionOrdersModel')->update_dio($insertionOrderId, $data);

            $kernel->getContainer()->get('HistoryInsertionOrdersModel')->change($insertionOrderId, $tblOldState);

            $pdfSave = self::get_listAction($insertionOrderId, 'api_pdf_save');

            self::generate_dio_pdf($pdfSave);

            if (isset($data['debug']) == 'test3') {
                print_r($update);
            }

            if (isset($update)) {
                return json_encode(array(
                    'success' => true,
                    'message' => 'DIO was updated',
                    //'deal_number' => $data['deal_number_label'],
                    'dio_id' => $insertionOrderId,
                    //'dio_link' => 'https://acq.gameloft.org/adserver/web/insertion-orders/get_edit/' . $dio_data->id . '/'
                    'dio_link' => 'https://acq.gameloft.org/adserver/web/insertion-orders/get_edit/' . $insertionOrderId . '/',
                ));
            } else {
                return json_encode(array(
                    'success' => false,
                    'message' => 'Something went wrong',
                    'dio_id' => $insertionOrderId,
                    //'deal_number' => $data['deal_number_label']
                ));
            }
        }
    }

    public function non_numeric_check($source, $value, $second_value = null)
    {
        if (!is_numeric($value)) {
            global $kernel;
            switch ($source) {
                case 'advertised_brands_industry_sector_category_id':
                    return $kernel->getContainer()->get('InsertionOrdersModel')->get_advertised_brands_industry_sector_categories_id($value);
                    break;
                case 'advertised_brands_industry_sector_id':
                    return $kernel->getContainer()->get('InsertionOrdersModel')->get_advertised_brands_industry_sectors_id($value);
                    break;
                case 'creative_type_id':
                    return $kernel->getContainer()->get('InsertionOrdersModel')->getAdFormatId($value);
                    break;
                case 'platform_id':
                    $platforms = $kernel->getContainer()->get('PlatformsModel')->get_list();
                    if (is_array($value)) {
                        if (in_array('All Platforms', $value)) return array(9);
                        return $this->sort_response_numeric($value, $platforms);
                    } else if ($value[0] == 'All Platforms') {
                        return array(9);
                    }
                    break;
                case 'country_id':
                    $countries = $kernel->getContainer()->get('CountriesModel')->get_list();
                    return $this->sort_response_numeric($value, $countries);
                    break;
                case 'pricing_model_id':
                    switch ($value) {
                        case 'Package':
                            return 10;
                            break;
                        case 'Fee':
                            return 11;
                            break;
                        default:
                            return $kernel->getContainer()->get('CampaignsModel')->get_capping_model_id($value)['id'];
                    }
                    break;
                case 'network':
                    return $kernel->getContainer()->get('InsertionOrdersModel')->getNetworkId($value);
                    break;
                case 'media_group':
                    if (empty($value) || is_null($value)) return 17;
                    $data = $kernel->getContainer()->get('InsertionOrdersModel')->get_media_groups($value);
                    if (empty($data)) {
                        $saveGroup = $kernel->getContainer()->get('MediaGroupsModel')->add_media_group($value);
                        $mediaGrpName = $kernel->getContainer()->get('InsertionOrdersModel')->get_media_groups($value);
                        if (isset($mediaGrpName)) {
                            return $mediaGrpName;
                        }
                    } else {
                        return $data;
                    }
                    break;
                case 'media_agency':
                    if (empty($second_value) || is_null($second_value) || $second_value == 17) return 0;
                    if (empty($value) || is_null($value)) return 0;
                    $data = $kernel->getContainer()->get('InsertionOrdersModel')->get_media_agency($value, $second_value);
                    if (empty($data)) {
                        $kernel->getContainer()->get('MediaGroupsModel')->add_media_agency(
                            array(
                                "mediaGroupId" => $second_value,
                                "mediaAgency" => $value,
                                "mediaAgencyTitle" => $value,
                            )
                        );
                        $mediaGrpName = $kernel->getContainer()->get('InsertionOrdersModel')->get_media_agency($value, $second_value);
                        if (isset($mediaGrpName)) {
                            return $mediaGrpName;
                        }
                    } else {
                        return $data;
                    }
                    break;
                case 'country':
                case 'country_id':
                    return $kernel->getContainer()->get('CountriesModel')->get_country_id($value);
                    break;
                case 'games_id':
                    $gameNames = [];
                    foreach ($value as $v) {
                        if ($v == 'Gameloft Games') return array(1);
                        if ($v == 'All Games') return array(2);
                        $gameNames[] = $kernel->getContainer()->get('GamesModel')->get_id_by_name($v);
                    }
                    return $gameNames;
                    break;
                case 'io_campaign_managers':
                case 'insertion_orders_contacts':
                    $contacts = [];

                    if (is_array($value)) {
                        foreach ($value as $contact) {
                            $data = $kernel->getContainer()->get('UsersModel')->get_user_details_by_email($contact);
                            if (!empty($data)) {
                                $contacts[] = $data->id;
                            }
                        }
                        return $contacts;
                    } else {
                        $userDetails = $kernel->getContainer()->get('UsersModel')->get_user_details_by_email($value);
                        if (empty($userDetails)) {
                            return 0;
                        } else {
                            return $kernel->getContainer()->get('UsersModel')->get_user_details_by_email($value)->id;
                        }
                    }
                    break;
                case 'invoicing_entity':
                    $invoicing_entity = $this->invoicing_entity_check($value);
                    return $invoicing_entity['invoicing_entity_id'];
                    break;
                default:
                    return $value;
                    break;
            }
        } else {
            return $value;
        }
    }

    public function numeric_check($source, $value)
    {

//        if(is_numeric($value) || is_array($value)) {
        global $kernel;
        switch ($source) {
            case 'advertised_brands_industry_sector_category_id':
                return $kernel->getContainer()->get('InsertionOrdersModel')->get_advertised_brands_industry_sector_categories($value);
                break;
            case 'advertised_brands_industry_sector_id':
                return $kernel->getContainer()->get('InsertionOrdersModel')->get_advertised_brands_industry_sectors($value);
                break;
            case 'creative_type_id':
                return $kernel->getContainer()->get('InsertionOrdersModel')->getAdFormatName($value);
                break;
            case 'platform_id':
                $platforms = $kernel->getContainer()->get('PlatformsModel')->get_list();

                if (is_array($value)) {
                    if (in_array(9, $value)) return 'All Platforms';
                    return $this->sort_response_numeric($value, $platforms);
                } else if ($value == '9') {
                    return array('All Platforms');
                } else {
                    $value = explode('-', $value);
                    return $this->sort_response_numeric($value, $platforms);
                }

                break;
            case 'country_id':
                $countries = $kernel->getContainer()->get('CountriesModel')->get_list();
                return $this->sort_response_numeric($value, $countries);
                break;
            case 'pricing_model_id':
                switch ($value) {
                    case 10:
                        return 'Package';
                        break;
                    case 11:
                        return 'Fee';
                        break;
                    default:
                        return $kernel->getContainer()->get('CampaignsModel')->get_capping_model($value)['name'];
                }
                break;
            case 'network':
                return $kernel->getContainer()->get('InsertionOrdersModel')->getNetworkName($value);
                break;
            case 'media_group':
                return $kernel->getContainer()->get('InsertionOrdersModel')->get_media_groups($value);
                break;
            case 'media_agency':
                return $kernel->getContainer()->get('InsertionOrdersModel')->get_media_agency($value);
                break;
            case 'country':
                if (!empty($value))
                    return $kernel->getContainer()->get('CountriesModel')->get_country_name($value);
                break;
            case 'games_id':
                $gameNames = [];
                if ($value == 1) return array("Gameloft Games");
                if ($value == 2) return array("All Games");
                if (!is_array($value)) {
                    $value = explode(',', $value);
                }
                foreach ($value as $v) {
                    if ($v == 1) return array("Gameloft Games");
                    if ($v == 2) return array("All Games");
                    if (!empty($v)) {
                        $gameNames[] = $kernel->getContainer()->get('GamesModel')->get_name_by_id($v);
                    }
                }
                return $gameNames;
                break;
            case 'io_campaign_managers':
            case 'insertion_orders_contacts':
                $contactResponse = [];
                foreach ($value as $contacts) {
                    $userData = $kernel->getContainer()->get('UsersModel')->get_user_details($contacts);
                    if (!empty($userData) && !in_array($userData->email, $contactResponse)) {
                        $contactResponse[] = $userData->email;
                    }
                }
                //$contactResponse = array_unique($contactResponse);

                return $contactResponse;
                break;
            case 'mediaGroup':
                return $kernel->getContainer()->get('InsertionOrdersModel')->get_media_groups($value);
                break;
            case 'mediaAgency':
                return $kernel->getContainer()->get('InsertionOrdersModel')->get_media_agency($value);
                break;
            default:
                return $value;
                break;
        }
//        } else {
//            return $value;
//        }

    }

    public function follow_dioAction()
    {
        $request = Request::createFromGlobals();
        try {
            $user_id = $request->request->get('user_id');
            $insertion_order_id = $request->request->get('insertion_order_id');
            $being_followed = $request->request->get('being_followed');

            if ($being_followed == 1) {
                $insertionOrderData = $this->get('InsertionOrdersModel')->addContacts($user_id, 3, $insertion_order_id);
            } else {
                $insertionOrderData = $this->get('InsertionOrdersModel')->deleteContacts($user_id, 3, $insertion_order_id);
            }
        } catch (Exception $e) {
            return new Response(json_encode(array('success' => false, 'error' => $e->getMessage())));

        }
        return new Response(json_encode(array('error' => false, 'result' => 'success')));
    }

    public function log_sales_force_data($dio_id, $data)
    {

        global $kernel;

        $kernel->getContainer()->get('InsertionOrdersModel')->log_sales_force_data($dio_id, $data);

    }

    public function dio_changes_notification ($data) {

        global $kernel;

        if($data['save_type'] == 2) {

            $adOpsNotification = [];

            $adOpsNotification['email_notification'] = [];

            $adOpsNotification['email_notification']['ad_format_new'] = [];

            $adOpsNotification['deal_number_label'] = $kernel->getContainer()->get('InsertionOrdersModel')->getDioDealNumber($data['dio_id']);

            foreach($data['creativeTypes'] as $key => $value) {

                $ad_format_data_DB = $kernel->getContainer()->get('InsertionOrdersModel')->getDioCreativeTypeInfoByCT($value['creative_type_id'],$data['dio_id'], $value['network'], $value['is_free']);

                if(isset($ad_format_data_DB[0])) {
                    $ad_format_data = $ad_format_data_DB[0];
                } else {
                    continue;
                }

                if($value['is_free'] == $ad_format_data['is_free']) {

                    $impressionsDB = str_replace(",", "", $ad_format_data['impressions_count']);
                    $impressions = str_replace(",", "", $value['impressions_count']);
                    $net_unit_price_DB = str_replace(",", "", $ad_format_data['net_unit_price']);

                    $unit_price_DB = str_replace(",", "", $ad_format_data['price']);

                    $net_unit_price = str_replace(",", "", $value['net_unit_price']);

                    $net_unit_price = number_format($net_unit_price, 4, '.', '');

                    $unit_price = str_replace(",", "", $value['price']);

                    $unit_price = number_format($unit_price, 4, '.', '');

                    if ($impressionsDB != $impressions ||
                        $unit_price != $unit_price_DB ||
                        $value['creative_type_id'] != $ad_format_data['creative_type_id'] ||
                        $value['pricing_model_id'] != $ad_format_data['pricing_model_id']) {

                        $value['ad_format_id'] = $ad_format_data['id'];

                        $adOpsNotification['ad_format_new'][$value['ad_format_id']] = $value;

                        $adOpsNotification['ad_format_new'][$value['ad_format_id']]['old_pricing_model'] = $ad_format_data['pricing_model_id'];

                        $adOpsNotification['ad_format_new'][$value['ad_format_id']]['old_creative_type_id'] = $ad_format_data['creative_type_id'];

                        $adOpsNotification['ad_format_new'][$value['ad_format_id']]['old_impressions'] = number_format($impressionsDB, 2, '.', '');

                        $adOpsNotification['ad_format_new'][$value['ad_format_id']]['old_unit_price'] = number_format($unit_price_DB, 2, '.', '');

                        $adOpsNotification['ad_format_new'][$value['ad_format_id']]['network_name'] = $value['network_name'] ;

                        $adOpsNotification['ad_ops_notification'] = 1;

                        $sendNotification = 1;

                        }
                    }

            }

            if (isset($adOpsNotification['ad_ops_notification']) && $adOpsNotification['ad_ops_notification'] == 1) {

                $adOpsNotification['io_campaign_managers'] = [];
                $adOpsNotification['insertion_orders_contacts'] = [];

                $dioData = self::get_listAction($data['dio_id'], 'api_pdf_save')[0];

                foreach($dioData['io_campaign_managers'] as $adops) {
                    $adOpsNotification['io_campaign_managers'][] = $kernel->getContainer()->get('UsersModel')->get_user_details($adops)->email;
                }
                foreach($dioData['insertion_orders_contacts'] as $adops) {
                    $adOpsNotification['insertion_orders_contacts'][] = $kernel->getContainer()->get('UsersModel')->get_user_details($adops)->email;
                }

                $adOpsNotification['email'] = $kernel->getContainer()->get('UsersModel')->get_user_details($data['user_id'])->email;

                $adOpsNotification['id'] = $data['dio_id'];

                $this->generate_notification($adOpsNotification, 0, 'changes');

            }
        }
    }

    public function get_budget_spendingAction ($dio_id) {
        return new JsonResponse(array($this->get('InsertionOrdersModel')->dio_budget_spending($dio_id)));
    }

    public function get_ad_typeAction() {
        global $kernel;

        $dioAdFormatData = $kernel->getContainer()->get('InsertionOrdersModel')->get_dio_ad_type();

        foreach($dioAdFormatData as $k => $dio) {
            $gladFormat = $kernel->getContainer()->get('InsertionOrdersModel')->get_dio_glads_ad_type($dio['id']);

            $dioAdFormatData[$k]['glads'] = [];

            foreach($gladFormat as $glads) {
                $dioAdFormatData[$k]['glads'][$glads->creative_type_id] = $glads->creative_type_id;
            }

        }

        return new JsonResponse($dioAdFormatData);

    }

}

?>
