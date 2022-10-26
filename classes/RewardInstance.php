<?php
namespace Stanford\Tremendous;

use DateTime;
use REDCap;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;

class RewardInstance
{

    public mixed $module, $brandIds;
    public int $project_id, $event_id, $repeat_instance, $amount;
    public string $record_id, $repeat_instrument, $title, $logic;
    public string $gc_message_field;
    public string $campaignId, $name, $statusValue;
    public string $createOrderURN, $listCampaignsURN, $tremendous_url, $fundingSource, $apiToken;
    public string $whichCommunication;
    public bool $useCampaign;
    public string $communication, $campaignName, $gc_status_field, $gc_timestamp_field;

    /**
     * Set the parameters needed to determine if a reward is needed or not and if so, how to process it
     *
     * @param mixed $module
     * @param int $pid
     * @param string $record_id
     * @param int $event_id
     * @param int $repeat_instance
     * @param string $repeat_instrument
     * @param array $config
     */
    public function __construct(mixed $module, int $pid, string $record_id, int $event_id,
                                int $repeat_instance, string $repeat_instrument, array $config)
    {
        // These fields define the REDCap project/record receiving the gc
        $this->module = $module;
        $this->project_id = $pid;
        $this->record_id = $record_id;
        $this->event_id = $event_id;
        $this->repeat_instance = $repeat_instance;
        $this->repeat_instrument = $repeat_instrument;

        // These are required gift card parameters in the project configuration
        $this->title = $config['reward-title'];
        $this->logic = $config['reward-logic'];
        $this->amount = $config['reward-amount'];

        // These are reward parameters which specify the fields which holds the participant info.
        $this->whichCommunication = $config['communication-type'];
        $commField = $config['communication-field'];
        $nameField = $config['recipient-name'];
        $params = array(
            "project_id"        => $this->project_id,
            "return_format"     => "json",
            "records"           => $this->record_id,
            "fields"            => array($commField,$nameField)
        );
        $demoEventId = $this->findDemoEventID($commField, $nameField);
        if (!empty($demoEventId)) {
            $params['events'] = array($demoEventId);
        }

        // We need to retrieve the actual data from the fields which store the fieldname.
        $jsonResults = REDCap::getData($params);
        //irvins check if results empty?
        if (!empty($jsonResults)) {
            $results = json_decode($jsonResults, true);
            $this->communication = $results[0][$commField];
            $this->name = $results[0][$nameField];
        }

        // These fields will be filled in with the results of the order request.
        $this->gc_status_field      = $config['reward-status'];
        $this->gc_message_field      = $config['reward-message'];
        $this->gc_timestamp_field   = $config['reward-timestamp'];
        $params = array(
            "project_id"        => $this->project_id,
            "return_format"     => "json",
            "records"           => $this->record_id,
            "events"            => $this->event_id,
            "fields"            => array($this->gc_status_field)
        );

        // Retrieve the status field to see if this record has already received a reward for this config
        $jsonResults = REDCap::getData($params);
        $results = json_decode($jsonResults, true);
        if (!empty($results)) {
            $this->statusValue = $results[0][$this->gc_status_field];
        } else {
            $this->statusValue = '';
        }

        // This should go into system config or can leave here if projects would like
        // to test in Sandbox before moving to production
        $this->tremendous_url = $this->module->getSystemSetting("tremendous-url");

        // These should go into project config - these are Tremendous specific values
        $this->fundingSource    = $this->module->getProjectSetting("tremendous-funding-id");
        $this->apiToken         = $this->module->getProjectSetting("tremendous-api-token");
        $this->useCampaign      = $config["use-campaign"];
        $this->campaignName     = (empty($config["campaign-name"]) ? '' : $config["campaign-name"]);
        $brands                 = (empty($config["brand-identifiers"]) ? '' : $config["brand-identifiers"]);
        if (!empty($brands)) {
            $this->brandIds = explode(',', $brands);
        } else {
            $this->brandIds = array();
        }

        // These can stay here
        $this->createOrderURN   = '/api/v2/orders';
        $this->listCampaignsURN    = '/api/v2/campaigns';
    }

    /**
     * This function finds the event_id where the demographics data resides.
     *
     * @param $commField
     * @param $nameField
     * @return int|string
     */
    function findDemoEventID($commField, $nameField) {

        global $Proj;

        $event_id = '';
        if (REDCap::isLongitudinal()) {
            $commForm = $Proj->metadata[$commField]["form_name"];
            $nameForm = $Proj->metadata[$nameField]["form_name"];
            if ($commForm == $nameForm) {
                foreach($Proj->eventsForms as $eid => $forms) {
                    if (in_array($commForm, $forms)) {
                        if (empty($event_id)) {
                            $event_id = $eid;

                            //irvins break loop?
                            break;
                        }
                    }
                }
            }
        }

        return $event_id;
    }


    /**
     * This function checks to see if the reward status is blank.  If so, check to see if this
     * record needs a reward. If the reward status is not blank, the record already received
     * an award so skip processing.
     *
     * @return bool [true = needs reward, false=does not need reward]
     */
    function checkRewardStatus() {

        $status = false;
        // Check to see if the reward status field is blank.  If not, a reward was already sent so don't process.
        if (empty($this->statusValue)) {

            $this->module->emDebug("GC Status field empty for record " . $this->record_id);
            // Check if the logic is set to true
            $status = REDCap::evaluateLogic($this->logic, $this->project_id, $this->record_id, $this->event_id,
                $this->repeat_instance, $this->repeat_instrument);
            $this->module->emDebug("Evaluation of logic: " . $status . ", for record: " . $this->record_id);
        }

        return $status;

    }

    /**
     * Go through the steps to process a record for a reward. If this is a campaign, we
     * need to find the campaign id so we can use it in the reward order.  Send out the
     * reward order and update the project so we know this record requested a reward.
     *
     * @return void
     */
    function processReward() {

        // if campaigns are being used, find the campaignID from the name given in the config file.
        if ($this->useCampaign) {
            $status = $this->retrieveCampaignID();
        }

        // If a campaign is not being used and no GC brands have been give, we can't process this config.
        if ((!empty($this->brandIds) and (!$this->useCampaign)) or
            (($this->useCampaign) and (!empty($this->campaignId)))) {
            // Create an Order and send to Tremendous
            [$status, $message] = $this->createOrder();
            $this->module->emDebug("Status: " . $status . ", message: " . $message);
        } else {
            $message = "There are no brands to choose";
            $status = false;
        }

        // Update this record to store the status of the request
        $this->updateRecord($status, $message);

    }

    /**
     * Retrieve the campaign ID from the campaign name listed in the config file.  This will
     * set the brands that are available to choose from and also mark this reward as part of
     * a campaign for reporting purposes in Tremendous.
     *
     * @return bool
     */
    function retrieveCampaignID() {

        $this->campaignId = '';
        // Retrieve the list of campaigns and return the one that matches our campaign name
        $header = array(
            "Authorization" => "Bearer " . $this->apiToken,
            "Content-type" => "application/json"
        );

        // Retrieve list of campaigns so we can find ID
        [$status, $response] = $this->sendAPIGet($this->listCampaignsURN, $header);
        if ($status) {
            $campaigns = json_decode($response, true);

            foreach ($campaigns['campaigns'] as $oneCampaign) {
                if ($oneCampaign['name'] == $this->campaignName) {
                    $this->campaignId = $oneCampaign['id'];
                    break;
                }
            }
        }

        return $status;
    }

    /**
     * Put together the reward request and send to Tremendous
     *
     * @return array
     */
    function createOrder() {

        // In order for the request to be successful, you must specify if you want
        // to send reward in email or text and you must send a name with it.
        if (!empty($this->communication) and !empty($this->name)) {

            // Setup the communication pathway
            $communicationType = strtolower($this->whichCommunication);
            // If a name was entered, add it to the request (email or phone). Name is required
            $recipient = array(
                "$communicationType"    => $this->communication,
                "name"                  => $this->name
            );

            // Put together the rest of the request
            $rewards = array(
                "pid" => $this->project_id,
                "reward_name" => $this->title,
                "record_id" => $this->record_id,
                "value" => array(
                    "denomination" => $this->amount,
                    "currency_code" => "USD"
                ),
                "delivery" => array(
                    "method" => $this->whichCommunication
                ),
                "recipient" => $recipient
            );

            // Setup the available brands - either by Campaign or by listing individual brand ids
            if ($this->useCampaign) {
                $rewards["campaign_id"] = $this->campaignId;
            } else {
                $rewards["products"] = $this->brandIds;
            }

            $body = array(
                "payment" => array("funding_source_id" => $this->fundingSource),
                "rewards" => array($rewards)
            );

            // $this->module->emDebug("This is the body: " . json_encode($body));

            $header = array(
                "Authorization" => "Bearer " . $this->apiToken,
                "Content-type" => "application/json"
            );

            // Make the request to Tremendous
            [$status, $message] = $this->sendAPIPost($this->createOrderURN, $header, $body);
            if ($status) {
                // Retrieve the order id so we can store it in the REDCap record
                $response = json_decode($message, true);
                $message = 'Order ID: '.$response['order']['id'] . "; Reward ID: " . $response['order']['rewards'][0]['id'];
            }

        } else {
            $this->module->emError("Cannot send reward to record " . $this->record_id .
                " because communication field or name was not entered");
            $message = "Either communication field or name is missing";
            $status = false;
        }

        return [$status, $message];
    }


    /**
     * Send the Post request to Tremendous.  So far, the only POST is to create an reward Order
     * but we may want to add more POST requests in the future.
     *
     * Returns a status of true when the http code is 200 otherwise status is false.  The message
     * tries to determine why the POST failed.
     *
     * @param $urn
     * @param $header
     * @param $body
     * @return array
     */
    function sendAPIPost(string $urn, array $header, array $body) {


        $message = "Error when requesting GC";
        // Create new client
        $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 5,
                "base_uri" => $this->tremendous_url,
                "exceptions" => false
            ]
        );

        //  Send POST request
        try {
            $response = $client->post($this->tremendous_url . $urn,
                [
                    'debug' => false,
                    'body' => json_encode($body),
                    'headers' => $header,
                ]
            );

            // Check return code
            $http_code = $response->getStatusCode();
            $this->module->emDebug("HTTP Code: " . $http_code);

            if ($http_code == 200) {
                $status = true;
                $message = $response->getBody()->getContents();
            } else {
                $this->module->emError("Response: " . $response->getReasonPhrase());
                $status = false;
            }

            // Catch exceptions
        } catch (TransferException $ex) {
            $this->module->emError("Transfer Exception occurred when requesting gc order.");
            $status = false;
        } catch (GuzzleException $ex) {
            $this->module->emError("Guzzle Exception occurred when requesting gc order.");
            $status = false;
        }

        return [$status, $message];
    }

    /**
     * Send an API GET request to Tremendous.  So far, we are only retrieving the active
     * Campaigns so we can add the available brand options to the request.
     *
     * Returns a status of true if we receive a html code of 200 and returns the response
     * received from the request.  Otherwise returns a status of false and tries to figure
     * out why the request fails.
     *
     * @param $urn
     * @param $header
     * @param string[] $header
     *
     * @return (array|bool|mixed)[]
     */
    function sendAPIGet(string $urn, array $header): array {

        $this->module->emDebug("In sendAPIGet: total URL " . $this->tremendous_url . $urn);
        // Create new client
        $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 5,
                "base_uri" => $this->tremendous_url,
                "exceptions" => false
            ]
        );

        //  Send GET request
        $returnData = array();
        try {
            $response = $client->get($this->tremendous_url . $urn,
                [
                    'debug' => false,
                    'headers' => $header,
                ]
            );

            // Check return code
            $http_code = $response->getStatusCode();
            $this->module->emDebug("HTTP GET Code: " . $http_code);

            if ($http_code == 200) {
                $status = true;
                $returnData = $response->getBody()->getContents();
            } else {
                $this->module->emError("Response: " . $response->getReasonPhrase());
                $status = false;
            }

            // Catch exceptions
        } catch (TransferException $ex) {
            $this->module->emError("Transfer Exception occurred when requesting gc order.");
            $status = false;
        } catch (GuzzleException $ex) {
            $this->module->emError("Guzzle Exception occurred when requesting gc order.");
            $status = false;
        }

        return [$status, $returnData];
    }

    /**
     * Update the REDCap record to put in the status of the request and when it happened.
     * Returns true if the updates were successfully saved, otherwise false.
     *
     * @param $status
     * @param $message
     * @return bool
     */
    function updateRecord($status, $message) {

        global $Proj;

        $now = (new DateTime())->format("Y-m-d H:i:s");
        if ($status) {
            $gc_status = "Requested";
        } else {
            $gc_status = '';
        }
        $fields = array(
            "$this->gc_message_field"       => $message,
            "$this->gc_status_field"        => $gc_status,
            "$this->gc_timestamp_field"     => $now
        );
        $params[$this->record_id][$this->event_id] = $fields;

        $response = REDCap::saveData($this->project_id, 'array', $params, 'overwrite');
        if (!empty($response['errors'])) {
            $this->module->emError("Could not save data for record " . $this->record_id, "Error is " . json_encode($response['errors']));
            return false;
        } else {
            return true;
        }
    }

}
