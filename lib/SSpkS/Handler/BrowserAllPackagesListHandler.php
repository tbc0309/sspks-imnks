<?php

namespace SSpkS\Handler;

use SSpkS\Output\HtmlOutput;
use SSpkS\Package\BrowserPackageCatalog;

final class BrowserAllPackagesListHandler extends AbstractHandler
{
    public function canHandle(): bool
    {
        if (!$this->isReadRequest()) {
            return false;
        }

        return count($_GET) === 1
            && isset($_GET['packages'])
            && $_GET['packages'] === 'fulllist';
    }

    public function handle(): void
    {
        $output = new HtmlOutput($this->config);
        // A non-empty architecture hides the repository notice.
        $output->setVariable('arch', 'fulllist');
        $output->setVariable('pageTitle', $this->language->get('all_packages') . ' - ' . $this->config->site['name']);
        $output->setVariable('catalogTitle', $this->language->get('all_packages'));
        $output->setVariable('emptyTitle', $this->language->get('no_packages'));
        $output->setVariable('emptyMessage', $this->language->get('database_empty'));

        try {
            $catalog = new BrowserPackageCatalog($this->config);
            $packages = $catalog->getAll();
            $packageCount = count($packages);
            $output->setVariable('packagelist', $packages);
            $output->setVariable('catalogSummary', $this->language->get('stats', ['models' => 'DSM 7', 'packages' => $packageCount]));
            $output->setVariable('seoDescription', $this->language->get('all_packages') . ': ' . $packageCount);
            $output->setTemplate('html_packagelist');
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read the complete package list: ' . $e->getMessage());
            http_response_code(503);
            header('Cache-Control: no-store');
            $output->setVariable('errorMessage', $this->language->get('data_error'));
            $output->setTemplate('html_packagelist_error');
        }

        $output->output();
    }
}
