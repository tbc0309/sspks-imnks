<?php

namespace SSpkS\Handler;

use SSpkS\Output\HtmlOutput;

final class NotFoundHandler extends AbstractHandler
{
    public function canHandle(): bool
    {
        return true;
    }

    public function handle(): void
    {
        http_response_code(404);
        header('Cache-Control: no-store');
        $output = new HtmlOutput($this->config);
        $output->setVariable('pageTitle', '404 · ' . $this->config->site['name']);
        $output->setVariable('noIndex', true);
        $output->setTemplate('html_404');
        $output->output();
    }
}
