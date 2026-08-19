<?php

namespace SSpkS\Handler;

final class BrowserRedirectHandler extends AbstractHandler
{
    public function canHandle(): bool
    {
        return !empty($this->config->site['redirectindex']);
    }

    public function handle(): void
    {
        header('Location: ' . $this->config->site['redirectindex']);
    }
}
