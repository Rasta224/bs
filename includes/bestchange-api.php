<?php
/**
 * BestChange data reader.
 * Reads pre-built cache files created by cron_update.php.
 * No downloading, no ZIP parsing -- just fast file reads.
 * 
 * If cache files don't exist, runs the initial update automatically.
 */

class BestChangeAPI {
    private $cachePath;
    private $currencies = null;
    private $exchangers = null;
    private $rates = null;
    private $error = '';

    public function __construct($cachePath = null) {
        $this->cachePath = $cachePath ?: __DIR__ . '/../cache';
    }

    public function getError() { return $this->error; }

    public function getCurrencies() {
        if ($this->currencies === null) {
            $file = $this->cachePath . '/currencies.json';
            if (file_exists($file)) {
                $this->currencies = json_decode(file_get_contents($file), true) ?: [];
            } else {
                $this->tryAutoUpdate();
                if (file_exists($file)) {
                    $this->currencies = json_decode(file_get_contents($file), true) ?: [];
                } else {
                    $this->currencies = [];
                    $this->error = 'No currency data. Run cron_update.php first.';
                }
            }
        }
        return $this->currencies;
    }

    public function getExchangers() {
        if ($this->exchangers === null) {
            $file = $this->cachePath . '/exchangers.json';
            if (file_exists($file)) {
                $this->exchangers = json_decode(file_get_contents($file), true) ?: [];
            } else {
                $this->exchangers = [];
            }
        }
        return $this->exchangers;
    }

    public function getExchangerName($id) {
        $ex = $this->getExchangers();
        return isset($ex[$id]) ? $ex[$id] : 'Exchanger #' . $id;
    }

    /**
     * Get rates for a specific direction.
     */
    public function getRates($giveId, $getId) {
        $giveId = (int)$giveId;
        $getId = (int)$getId;

        if ($this->rates === null) {
            $file = $this->cachePath . '/rates.dat';
            if (file_exists($file)) {
                $this->rates = @unserialize(file_get_contents($file));
                if (!is_array($this->rates)) $this->rates = [];
            } else {
                $this->tryAutoUpdate();
                if (file_exists($file)) {
                    $this->rates = @unserialize(file_get_contents($file)) ?: [];
                } else {
                    $this->rates = [];
                }
            }
        }

        $key = $giveId . '_' . $getId;
        return isset($this->rates[$key]) ? $this->rates[$key] : [];
    }

    /**
     * If no cache files exist, try to run cron_update.php once automatically.
     */
    private function tryAutoUpdate() {
        $marker = $this->cachePath . '/auto_update_attempted';
        if (file_exists($marker)) return; // don't retry
        @file_put_contents($marker, time());

        $cronScript = dirname(__DIR__) . '/cron_update.php';
        if (file_exists($cronScript)) {
            // Include the cron script to do initial data fetch
            @ob_start();
            @include $cronScript;
            @ob_end_clean();
        }
    }
}
