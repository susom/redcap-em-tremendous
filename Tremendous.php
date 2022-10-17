<?php
namespace Stanford\Tremendous;

use Exception;

require_once "emLoggerTrait.php";
require_once "classes/RewardInstance.php";

class Tremendous extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public function __construct() {
		parent::__construct();
	}

	function redcap_save_record ($project_id, $record, $instrument, $event_id, $group_id,
                                 $survey_hash, $response_id, $repeat_instance) {

        // Retrieve the Reward configurations
        $configs = $this->getSubSettings("rewards");

        foreach ($configs as $config => $config_info) {

            // Check to see if this event is in a reward configuration otherwise we just skip
            if ($event_id == $config_info['event-id']) {

                // Check this config to see if a reward needs to be sent
                try {
                    $reward = new RewardInstance($this, $project_id, $record, $event_id, $repeat_instance, $instrument, $config_info);
                } catch (Exception $ex) {
                    $this->emError("Exception when instantiating RewardInstance for project $project_id", $ex->getMessage());
                    return;
                }

                try {
                    $status = true;
                    //[$status, $message] = $reward->verifyConfig();
                    if ($status) {
                        $eligible = $reward->checkRewardStatus();
                    }
                    if ($eligible) {
                        $reward->processReward();
                    }
                } catch (Exception $ex) {
                    $this->emError("Exception when processing reward for project $project_id", $ex->getMessage());
                }

            }
        }
    }


}
