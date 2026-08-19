<?php

namespace SSpkS\Handler;

use SSpkS\Device\DeviceList;
use SSpkS\Output\HtmlOutput;

final class BrowserDeviceListHandler extends AbstractHandler
{
    public function canHandle(): bool
    {
        return $this->isReadRequest() && $_GET === [];
    }

    public function handle(): void
    {
        $output = new HtmlOutput($this->config);
        $output->setVariable('seoDescription', $this->language->get('model_intro'));
        try {
            $deviceList = new DeviceList($this->config);
            $models = $deviceList->getDevices($this->config->models['priority_models']);
            if ($models === []) {
                $output->setTemplate('html_modellist_none');
            } else {
                $output->setVariable('modelCount', count($models));
                $output->setVariable('modellist', $models);
                $output->setTemplate('html_modellist');
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read the model list: ' . $e->getMessage());
            http_response_code(503);
            header('Cache-Control: no-store');
            $output->setVariable('errorMessage', $this->language->get('data_error'));
            $output->setTemplate('html_modellist_error');
        }
        $output->output();
    }
}
