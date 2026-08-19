<?php

namespace SSpkS\Handler;

use SSpkS\Config;
use SSpkS\Language;

abstract class AbstractHandler
{
    protected Config $config;
    protected Language $language;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->language = Language::getInstance($config);
    }

    protected function isReadRequest(): bool
    {
        return in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true);
    }

    abstract public function canHandle(): bool;

    abstract public function handle(): void;
}
