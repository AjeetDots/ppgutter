<?php

namespace FluentFormPro\Integrations\GoogleSheet\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}


class Sheet
{
    protected $api;

    protected $baseUrl = 'https://sheets.googleapis.com/v4/spreadsheets/';

    public function __construct()
    {
        $this->api = new API();
    }

    public function getHeader($sheetId, $workSheetName)
    {
        $url = $this->baseUrl.$sheetId.'/values/'.rawurlencode($workSheetName).'!A1:Z1';

        $headers = [
            'Authorization' => 'OAuth '.$this->api->getAccessToken()
        ];

        return $this->api->makeRequest($url, [], 'GET', $headers);
    }

    public function insertHeader($sheetId, $workSheetName, $row, $range = 'auto')
    {
        $range = $workSheetName.'!A1:'.$this->getRangeKey(count($row)).'1';
        $encodedRange = rawurlencode($workSheetName).'!A1:'.$this->getRangeKey(count($row)).'1';

        $this->clear($sheetId, $encodedRange);
        $rowValues = array_values($row);

        $queryString = http_build_query([
            'valueInputOption' => 'RAW',
            'includeValuesInResponse' => 'true',
            'responseValueRenderOption' => 'UNFORMATTED_VALUE',
            'responseDateTimeRenderOption' => 'FORMATTED_STRING',
        ]);
        $url = $this->baseUrl.$sheetId.'/values/'.$encodedRange.'?'.$queryString;

        return $this->api->makeRequest($url, [
            'values' => [$rowValues],
            'majorDimension' => 'ROWS',
            'range' => $range
        ], 'PUT', $this->getStandardHeader());
    }

    public function insertRow($sheetId, $workSheetName, $row)
    {
        $encodedRange = rawurlencode($workSheetName).'!A1:'.$this->getRangeKey(count($row)).'1';

        $queryString = http_build_query([
            'valueInputOption' => 'USER_ENTERED',
            'includeValuesInResponse' => 'true',
            'insertDataOption' => 'INSERT_ROWS',
            'responseValueRenderOption' => 'UNFORMATTED_VALUE',
            'responseDateTimeRenderOption' => 'SERIAL_NUMBER',
        ]);

        $url = $this->baseUrl.$sheetId.'/values/'.$encodedRange.':append?'.$queryString;
        
        $rowValues = array_values($row);

        $rowValues = array_map(function ($value) {
            if(is_numeric($value)) {
                $calcValue = ($value * 100) / 100;
                if(!is_infinite($calcValue)) {
                    return $calcValue;
                } else {
                    return $value;
                }
            }
            return $value;
        }, $rowValues);

        return $this->api->makeRequest($url, [
            'values' => [$rowValues]
        ], 'POST', $this->getStandardHeader());
    }

    private function clear($sheetId, $range)
    {
        $url = $this->baseUrl.$sheetId.'/values/'.$range.':clear';

        return $this->api->makeRequest($url, [], 'POST', $this->getStandardHeader());
    }
    
    protected function getRangeKey($num)
    {
        $rounds = 1;
        $remaining = 0;
        $range = range('A', 'Z');
        
        if ($num > 26) {
            $rounds = $num / 26;
            $remaining = $num % 26;
            $rounds = (round($rounds) + ($remaining ? 1 : 0) - 1);
        }
        
        foreach (range(0, $rounds) as $round) {
            foreach (range('A', 'Z') as $key => $char) {
                $range[] = $range[$round] . $char;
            }
        }
    
        $index = $num - 1;
    
        if (isset($range[$index])) {
            return $range[$index];
        }
    
        return 'CZ';
    }

    private function getStandardHeader()
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->api->getAccessToken()
        ];
    }
}
