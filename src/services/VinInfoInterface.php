<?php

namespace src\services;

interface VinInfoInterface
{
    /**
     * Парсинг данных по VIN автомобиля
     *
     * @param $vin
     * @return array
     */
    function getVinInfo($vin): array;
}