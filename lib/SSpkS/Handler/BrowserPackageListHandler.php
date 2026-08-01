<?php

namespace SSpkS\Handler;

use SSpkS\Device\DeviceList;
use SSpkS\Output\HtmlOutput;
use SSpkS\Package\BrowserPackageCatalog;

final class BrowserPackageListHandler extends AbstractHandler
{
    public function canHandle(): bool
    {
        return ($this->isReadRequest()
            && count($_GET) === 1
            && isset($_GET['arch'])
            && is_string($_GET['arch'])
            && strlen(trim((string) $_GET['arch'])) <= 64
            && preg_match('/^[a-z0-9_]+$/i', trim((string) $_GET['arch'])) === 1);
    }

    public function handle(): void
    {
        $arch = strtolower(trim((string) $_GET['arch']));

        try {
            $deviceList = new DeviceList($this->config);
            if (!$deviceList->isKnownArchitecture($arch)) {
                (new NotFoundHandler($this->config))->handle();
                return;
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Architecture validation failed: ' . $e->getMessage());
            http_response_code(503);
            header('Cache-Control: no-store');
            return;
        }

        $models = $deviceList->getModelsForArchitecture($arch);
        $modelCount = count($models);
        $isChinese = in_array($this->language->code(), ['chs', 'cht'], true);
        $modelPreview = implode($isChinese ? '、' : ', ', array_slice($models, 0, 6));
        if ($modelCount > 6) {
            $modelPreview .= $isChinese ? ' 等' : '…';
        }

        $output = new HtmlOutput($this->config);
        $output->setVariable('arch', $arch);
        $output->setVariable('pageTitle', $arch . ' DSM 7 SPK - ' . $this->config->site['name']);
        $output->setVariable('catalogTitle', $this->language->get('catalog_title', ['arch' => $arch]));
        $output->setVariable('emptyTitle', $this->language->get('no_compatible'));
        $output->setVariable('emptyMessage', $this->language->get('architecture_empty'));

        try {
            $catalog = new BrowserPackageCatalog($this->config);
            $packages = $catalog->getForArchitecture($arch);
            $packageCount = count($packages);
            $output->setVariable('packagelist', $packages);
            $summaryKey = $modelCount > 0 ? 'catalog_summary_models' : 'catalog_summary_arch';
            $output->setVariable('catalogSummary', $this->language->get($summaryKey, [
                'model_preview' => $modelPreview,
                'model_count' => $modelCount,
                'package_count' => $packageCount,
                'arch' => $arch,
            ]));
            $output->setVariable('seoDescription', $arch . ' DSM 7 SPK: ' . $packageCount);
            $output->setTemplate('html_packagelist');
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read the package list for architecture ' . $arch . ': ' . $e->getMessage());
            http_response_code(503);
            header('Cache-Control: no-store');
            $output->setVariable('arch', $arch);
            $output->setVariable('errorMessage', $this->language->get('data_error'));
            $output->setTemplate('html_packagelist_error');
        }
        $output->output();
    }
}
