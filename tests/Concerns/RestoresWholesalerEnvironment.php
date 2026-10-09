<?php

namespace Tests\Concerns;

use Illuminate\Support\Env;

/** Las credenciales ficticias de un conector no deben pasar a otras pruebas. */
trait RestoresWholesalerEnvironment
{
    private array $wholesalerProcessEnv;
    private array $wholesalerArrayEnv;
    private array $wholesalerServerEnv;

    protected function saveWholesalerEnvironment(): void
    {
        $this->wholesalerProcessEnv = $this->wholesalerVariables(getenv());
        $this->wholesalerArrayEnv = $this->wholesalerVariables($_ENV);
        $this->wholesalerServerEnv = $this->wholesalerVariables($_SERVER);
    }

    protected function tearDown(): void
    {
        foreach (array_unique(array_merge(array_keys($this->wholesalerVariables(getenv())), array_keys($this->wholesalerProcessEnv))) as $key) {
            putenv(array_key_exists($key, $this->wholesalerProcessEnv) ? $key.'='.$this->wholesalerProcessEnv[$key] : $key);
        }
        foreach (array_keys($this->wholesalerVariables($_ENV)) as $key) {
            unset($_ENV[$key]);
        }
        foreach (array_keys($this->wholesalerVariables($_SERVER)) as $key) {
            unset($_SERVER[$key]);
        }
        $_ENV = array_replace($_ENV, $this->wholesalerArrayEnv);
        $_SERVER = array_replace($_SERVER, $this->wholesalerServerEnv);
        Env::disablePutenv();
        Env::enablePutenv();
        parent::tearDown();
    }

    private function wholesalerVariables(array $values): array
    {
        return array_filter($values, fn ($key) => str_starts_with($key, 'WHOLESALER_'), ARRAY_FILTER_USE_KEY);
    }
}
