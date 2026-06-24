<?php

namespace YaleREDCap\REDCapServiceNowCatalogIntegration;

use ExternalModules\AbstractExternalModule;
use ExternalModules\Framework;

/**
 * @property Framework $framework
 * @see Framework
 */
class REDCapServiceNowCatalogIntegration extends AbstractExternalModule
{

    public function checkCatalogChanges()
    {
    
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)  {
        if ($action === 'testConnection') {
            return $this->testConnection($payload);
        } else if ($action === 'saveConnectionSettings') {
            return $this->saveConnectionSettings($payload);
        } else if ($action === 'getServiceNowFields') {
            $catalogItemId = $payload['sysId'] ?? '';
            if (empty($catalogItemId)) {
                return ['success' => false, 'error' => 'Missing required parameter: sysId.'];
            }

            return $this->getServiceNowFields($catalogItemId);
        } else if ($action === 'saveConfiguration') {
            return $this->saveConfiguration($payload);
        }
    }

    private function saveConnectionSettings(array $payload)
    {
        $instanceUrl = $payload['instanceUrl'] ?? '';
        $username = $payload['username'] ?? '';
        $password = $payload['password'] ?? '';
        if (empty($instanceUrl) || empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'Missing required parameters.'];
        }
        $this->framework->setProjectSetting('servicenow-instance-url', $instanceUrl);
        $this->framework->setProjectSetting('servicenow-username', $username);
        $this->framework->setProjectSetting('servicenow-password', $password);
        return ['success' => true, 'message' => 'Connection settings saved successfully.'];
    }

    private function saveConfiguration(array $payload)
    {
        $sysId = $payload['sysId'] ?? '';
        $fieldMappings = $payload['fieldMappings'] ?? [];

        if (empty($sysId)) {
            return ['success' => false, 'message' => 'Missing required parameter: sysId.'];
        }
        if (empty($fieldMappings) || !is_array($fieldMappings)) {
            return ['success' => false, 'message' => 'Missing or invalid required parameter: fieldMappings.'];
        }

        $this->framework->setProjectSetting('servicenow-catalog-sys-id', $sysId);
        $this->framework->setProjectSetting('field-mappings', $fieldMappings);
        return ['success' => true, 'message' => 'Configuration saved successfully.'];
    }

    private function testConnection($payload)
    {
        $instanceUrl = $payload['instanceUrl'] ?? '';
        $username = $payload['username'] ?? '';
        $password = $payload['password'] ?? '';

        if (empty($instanceUrl) || empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'Missing required parameters.'];
        }

        // If the provided password is a placeholder, retrieve the actual password from project settings
        if ($password === '__SAVED__') {
            $password = $this->framework->getProjectSetting('servicenow-password');
            $this->framework->log("Using saved password for ServiceNow connection test: $password");
        }

        // Ensure the instance URL does not end with a slash
        $instanceUrl = rtrim($instanceUrl, '/');

        // Construct the ServiceNow API URL for testing
        $testUrl = $instanceUrl . '/api/sn_sc/servicecatalog/catalogs';

        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $testUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Basic ' . base64_encode("$username:$password"),
            ),
            // CURLOPT_USERPWD => "$username:$password",
            // CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false, // For testing purposes; consider enabling in production
        ]);
        // curl_setopt($ch, CURLOPT_URL, $testUrl);
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        // curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing purposes; consider enabling in production

        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->framework->log("ServiceNow connection test response: HTTP $httpCode, Response: $response");

        $result = [];
        if ($httpCode === 200) {
            $result = ['success' => true, 'message' => 'Connection successful!'];
        } else {
            $result = ['success' => false, 'message' => 'Connection failed. HTTP Code: ' . $httpCode];
        }
        return $result;
    }

    private function getServiceNowConnectionSettings()
    {
        $instanceUrl = $this->framework->getProjectSetting('servicenow-instance-url');
        $username = $this->framework->getProjectSetting('servicenow-username');
        $password = $this->framework->getProjectSetting('servicenow-password');

        return [
            'instanceUrl' => $instanceUrl,
            'username' => $username,
            'password' => $password,
        ];
    }

    private function getServiceNowFields($catalogItemId)
    {
        // Ensure the instance URL does not end with a slash
        $settings = $this->getServiceNowConnectionSettings();
        $instanceUrl = rtrim($settings['instanceUrl'], '/');
        $username = $settings['username'];
        $password = $settings['password'];

        // Construct the ServiceNow API URL for fetching catalog item fields
        $fieldsUrl = $instanceUrl . '/api/sn_sc/servicecatalog/items/' . urlencode($catalogItemId) . '/variables';

        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fieldsUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Basic ' . base64_encode("$username:$password"),
            ),
            CURLOPT_SSL_VERIFYPEER => false, // For testing purposes; consider enabling in production
        ]);

        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return ['success' => true, 'data' => json_decode($response, true)];
        } else {
            return ['success' => false, 'error' => 'Failed to fetch fields. HTTP Code: ' . $httpCode, 'responseJSON' => $response];
        }
    }

}